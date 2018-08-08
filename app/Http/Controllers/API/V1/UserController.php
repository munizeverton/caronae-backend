<?php

namespace Caronae\Http\Controllers\API\v1;

use Cache;
use Caronae\Http\Controllers\BaseController;
use Caronae\Http\Requests\SignUpRequest;
use Caronae\Http\Requests\UpdateUserRequest;
use Caronae\Http\Resources\InstitutionResource;
use Caronae\Http\Resources\RideResource;
use Caronae\Http\Resources\UserResource;
use Caronae\Models\User;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Facebook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends BaseController
{
    const USER_CONTENT_DISK = 'user_content';
    const USER_RIDES_CACHE_TIME_MINUTES = 1;

    public function store(SignUpRequest $request)
    {
        $institutionID = $request->input('id_ufrj');
        if (!$user = User::findByInstitutionId($institutionID)) {
            $user = new User;
            $user->generateToken();
            $user->institution()->associate($request->institution);

            Log::info("Novo cadastro (id institucional: $institutionID)");
        }

        $ignoredFields = ['token'];
        if (!empty($user->profile_pic_url)) {
            $ignoredFields[] = 'profile_pic_url';
        }

        $user->fill($request->except($ignoredFields));
        $user->save();

        $token = JWTAuth::fromUser($user);
        return [ 'user' => new UserResource($user), 'token' => $token ];
    }

    public function login(Request $request)
    {
        $this->validate($request, [
            'id_ufrj' => 'required',
            'token' => 'required'
        ]);

        $user = User::where(['id_ufrj' => $request->input('id_ufrj'), 'token' => $request->input('token')])->first();
        if ($user == null || $user->banned) {
            return $this->error('User not found with provided credentials.', 401);
        }

        $response = ['user' => new UserResource($user)];

        if ($this->isLegacyAPI($request)) {
            $drivingRides = $user->rides()->where(['status' => 'driver', 'done' => false])->get();
            $response += ['rides' => $drivingRides];
        } else {
            $institution = $user->institution()->first();
            $response += ['institution' => new InstitutionResource($institution)];
        }

        return $response;
    }

    public function show(User $user)
    {
        return ['user' => new UserResource($user)];
    }

    /**
     * @todo Remove this after apps have migrated to using JWT token
     */
    public function getToken(User $user)
    {
        $token = JWTAuth::fromUser($user);
        return response(['message' => 'Ok'])->header('Authorization', "Bearer $token");
    }


    public function getRides(User $user)
    {
        Log::info("Carregando caronas do usuário", ['id' => $user->id]);

        list($pendingRides, $activeRides, $offeredRides) = $this->getRidesCached($user);

        return [
            'pending_rides' => RideResource::collection($pendingRides),
            'active_rides' => RideResource::collection($activeRides),
            'offered_rides' => RideResource::collection($offeredRides),
        ];
    }

    private function getRidesCached(User $user)
    {
        return Cache::remember('user-' . $user->id . '-rides', self::USER_RIDES_CACHE_TIME_MINUTES, function () use ($user) {
            Log::info("Caronas do usuário não encontradas em cache. Carregando do banco de dados.", ['id' => $user->id]);
            $pendingRides = $user->pendingRides()->with('riders')->get();
            $activeRides = $user->activeRides()->with('riders')->get();
            $offeredRides = $user->availableRides()->with('riders')->get()->diff($activeRides);
            return [$pendingRides, $activeRides, $offeredRides];
        });
    }

    public function getOfferedRides(User $user)
    {
        $rides = $user->offeredRides()
            ->inTheFuture()
            ->notFinished()
            ->with('riders')
            ->get();

        return ['rides' => RideResource::collection($rides)];
    }

    public function getPendingRides(User $user)
    {
        $rides = $user->pendingRides()->get();

        return ['rides' => RideResource::collection($rides)];
    }

    public function getRidesHistory(User $user, Request $request)
    {
        $offeredRides = $user->offeredRides()->finished();
        $takenRides = $user->acceptedRides()->finished();

        $response = [
            'offered_rides_count' => $offeredRides->count(),
            'taken_rides_count' => $takenRides->count(),
        ];

        if ($user == $request->user()) {
            $offeredRides = $offeredRides->with('riders')->get();
            $takenRides = $takenRides->with('riders')->get();
            $rides = $offeredRides->concat($takenRides);

            $response += [
                'rides' => RideResource::collection($rides),
            ];
        }

        return $response;
    }

    public function update(User $user = null, UpdateUserRequest $request)
    {
        if (!$user) {
            $user = $request->user();
        }

        $user->update($request->profile());

        return ['user' => new UserResource($user)];
    }

    public function uploadProfilePicture(User $user, Request $request)
    {
        $this->validate($request, [
            'profile_picture' => 'required|image',
        ]);

        Log::info('Uploading profile picture.', ['user_id' => $user->id]);

        $imagePath = $request->file('profile_picture')->store('profile_picture', self::USER_CONTENT_DISK);
        $imageURL = Storage::disk(self::USER_CONTENT_DISK)->url($imagePath);

        return ['profile_pic_url' => $imageURL];
    }

    public function saveFacebookId(Request $request)
    {
        $this->validate($request, [
            'id' => 'required'
        ]);

        $user = $request->user();
        $user->face_id = $request->input('id');
        $user->save();
    }

    public function saveProfilePicUrl(Request $request)
    {
        $this->validate($request, [
            'url' => 'required'
        ]);

        $request->user()->profile_pic_url = $request->url;
        $request->user()->save();
    }

    public function getMutualFriends(Request $request, Facebook $fb, $fbID)
    {
        $fbToken = $request->header('Facebook-Token');
        if ($fbToken == null) {
            return $this->error('User\'s Facebook token missing.', 403);
        }

        try {
            $response = $fb->get('/' . $fbID . '?fields=context.fields(mutual_friends)', $fbToken);
        } catch(FacebookSDKException $e) {
            return $this->error('Facebook SDK returned an error: ' . $e->getMessage(), 500);
        }

        $context = $response->getGraphNode()['context'];
        if (!array_key_exists('mutual_friends', $context)) {
            Log::warning('Facebook SDK returned an empty response for mutual_friends.');
            return ['total_count' => 0, 'mutual_friends' => []];
        }

        $mutualFriendsFB = $context['mutual_friends'];
        $totalFriendsCount = $mutualFriendsFB->getMetaData()['summary']['total_count'];
        $mutualFriendsFB = collect($mutualFriendsFB)->pluck('id');
        $mutualFriends = User::whereIn('face_id', $mutualFriendsFB)->get();

        return ['total_count' => $totalFriendsCount, 'mutual_friends' => UserResource::collection($mutualFriends)];
    }
}

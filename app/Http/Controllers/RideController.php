<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Ride;
use App\User;
use App\RideUser;

class RideController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index() {
		//
	}

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $decode = json_decode($request->getContent());
		
        $ride = new Ride();
		$ride->myzone = $decode->myzone;
		$ride->neighborhood = $decode->neighborhood;
		$ride->place = $decode->place;
		$ride->route = $decode->route;
		$ride->mydate = $decode->mydate;
		$ride->mytime = $decode->mytime;
		$ride->slots = $decode->slots;
		$ride->hub = $decode->hub;
		$ride->description = $decode->description;
		$ride->going = $decode->going;
		
		$ride->save();
		
        $user = User::where('token', $request->header('token'))->first();
		
        $ride_user = new RideUser();
        $ride_user->user_id = $user->id;
        $ride_user->ride_id = $ride->id;
        $ride_user->status = 0;
        
		$ride_user->save();
		
		return $ride->id;
    }
	
	public function requestJoin(Request $request) {
        $decode = json_decode($request->getContent());
        $user = User::where('token', $request->header('token'))->first();
		
		$matchThese = ['ride_id' => $decode->rideId, 'user_id' => $user->id];
        $ride_user = RideUser::where($matchThese)->first();
		
		if ($ride_user != null)
			return;
		
        $ride_user = new RideUser();
        $ride_user->user_id = $user->id;
        $ride_user->ride_id = $decode->rideId;
		$ride_user->status = 1;
        
		$ride_user->save();
	}
	
    public function listFiltered(Request $request)
    {
        $decode = json_decode($request->getContent());
		
		$matchThese = ['going' => $decode->go, 'mydate' => $decode->date];
		
		$locations = explode(", ", $decode->location);
		
		if ($locations[0] == "Centro" || $locations[0] == "Zona Sul" || $locations[0] == "Zona Oeste" || $locations[0] == "Zona Norte" || $locations[0] == "Baixada" || $locations[0] == "Grande Niterói")
				$locationColumn = 'myzone';
		else
				$locationColumn = 'neighborhood';
			
		$rides = Ride::where($matchThese)->whereIn($locationColumn, $locations)->get();
		
		if ($rides->count() > 0) {
			$resultJson = '[';
			
			foreach ($rides as $ride) {
				$matchThese2 = ['ride_id' => $ride->id, 'status' => 1];
				$matchThese3 = ['ride_id' => $ride->id, 'status' => 2];
				if (RideUser::where($matchThese2)->orWhere($matchThese3)->count() < $ride->slots) {
					$user = $ride->users()->where('status', 0)->first();
					
					$arr = array('driverName' => $user->name, 
										'course' => $user->course, 
										'neighborhood' => $ride->neighborhood, 
										'zone' => $ride->myzone, 
										'place' => $ride->place, 
										'route' => $ride->route, 
										'time' => $ride->mytime, 
										'date' => $ride->mydate, 
										'slots' => $ride->slots, 
										'hub' => $ride->hub, 
										'going' => $ride->going, 
										'rideId' => $ride->id, 
										'driverId' => $user->id);
					
					$resultJson .= json_encode($arr) . ',';
				}
			}
			
			if (strlen($resultJson) > 1) {
				$resultJson = substr($resultJson, 0, -1);  
				$resultJson .= ']';
				
				return $resultJson;
			}
		}
    }
	
	public function getMyActiveRides(Request $request) {
        $user = User::where('token', $request->header('token'))->first();
		
		$rides = $user->rides;
		$resultArray = array();
		foreach($rides as $ride) {
			if ($ride->pivot->status == 0 || $ride->pivot->status == 2) {
					$users = $ride->users;
					
					$users2 = array();
					foreach($users as $user2) {
						if ($user2->pivot->status == 0) {
							//$users3[] = $user2;
							array_unshift($users2, $user2);
						}
						if ($user2->pivot->status == 2) {
							//$users3[] = $user2;
							array_push($users2, $user2);
						}
					}
					
					if(count($users2) > 1) {
						//$resultArray[$ride] = $users2;
						array_push($resultArray, array("ride" => $ride, "users" => $users2));
					}
			}
		}
		
		return $resultArray;
	}
	
	public function leaveRide(Request $request) {
		
        $user = User::where('token', $request->header('token'))->first();
        $decode = json_decode($request->getContent());
		
		$matchThese = ['ride_id' => $decode->rideId, 'user_id' => $user->id];
        $rideUser = RideUser::where($matchThese)->first();
		if ($rideUser->status == 0) {
			RideUser::where('ride_id', $decode->rideId)->delete();
			Ride::find($decode->rideId)->delete();
		} else {
			$rideUser->status = 4;
			$rideUser->save();
		}
}
	
	public function delete(Request $request)
    {
        $decode = json_decode($request->getContent());
        RideUser::where('ride_id', $decode->rideId)->delete();
        Ride::find($decode->rideId)->delete();
    }
	
	public function getRequesters(Request $request)
    {
        $decode = json_decode($request->getContent());
        $ride = Ride::find($decode->rideId);
        $users = $ride->users;
		
		$requesters = array();
		foreach($users as $user) {
			if ($user->pivot->status == 1) {
				array_push($requesters, $user);
			}
		}

        return $requesters;
    }
	
	public function answerJoinRequest(Request $request)
    {
        $decode = json_decode($request->getContent());
		
		$matchThese = ['ride_id' => $decode->rideId, 'user_id' => $decode->userId, 'status' => 1];
        $rideUser = RideUser::where($matchThese)->first();
		$rideUser->status = $decode->accepted ? 2 : 3;
		
		$rideUser->save();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }
}

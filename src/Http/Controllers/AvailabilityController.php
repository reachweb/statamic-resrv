<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Exceptions\AvailabilityDurationException;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date'
        ]);

        try {
            $availabilityData = Availability::GetAvailabilityForDates($data);
        } catch (AvailabilityDurationException $exception) {
            return response()->json(['error' => $exception->getMessage()]);
        }      
       
        return response()->json($availabilityData);

    }

    public function show(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date'
        ]);
        
        try {
            $availabilityData = Availability::GetAvailabilityForDates($data, $statamic_id);
        } catch (AvailabilityDurationException $exception) {
            return response()->json(['error' => $exception->getMessage()]);
        }      
       
        return response()->json($availabilityData);
    }
}

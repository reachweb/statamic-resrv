<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date'
        ]);

        $availabilityData = Availability::GetAvailabilityForDates($data);
       
        return response()->json($availabilityData);

    }
}

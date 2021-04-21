<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\CarbonPeriod;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityCpController extends Controller
{
    public function index($statamic_id)
    {
        $results = Availability::where('statamic_id', $statamic_id)
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');
        return response()->json($results);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'price' => 'required|numeric',
            'available' => 'required|numeric',
        ]);

        $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
        
        $dataToAdd = [];
        foreach ($period as $day) {        
            
            $dataToAdd[] = [
                'statamic_id' => $data['statamic_id'],
                'date' => $day->isoFormat('YYYY-MM-DD'),
                'price' => $data['price'],
                'available' => $data['available']
            ];
        }

        Availability::upsert($dataToAdd, ['statamic_id', 'date'], ['price', 'available']);

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }
}

<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Requests\AdvancedAvailabilityCpRequest;
use Reach\StatamicResrv\Models\AdvancedAvailability;

class AdvancedAvailabilityCpController extends Controller
{
    public function index($statamic_id, $property)
    {
        $results = AdvancedAvailability::where('statamic_id', $statamic_id)
            ->where('property', $property)
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');

        return response()->json($results);
    }

    public function update(AdvancedAvailabilityCpRequest $request)
    {
        $data = $request->validated();

        foreach ($data['advanced'] as $property) {
            $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
            $dataToAdd = [];

            foreach ($period as $day) {
                $dataToAdd[] = [
                    'statamic_id' => $data['statamic_id'],
                    'date' => $day->isoFormat('YYYY-MM-DD'),
                    'price' => $data['price'],
                    'available' => $data['available'],
                    'property' => $property['code'],
                ];
            }
            AdvancedAvailability::upsert($dataToAdd, ['statamic_id', 'date', 'property'], ['price', 'available']);
        }

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'advanced' => 'required|array',
        ]);

        (new AdvancedAvailability)->deleteForDates($data['date_start'], $data['date_end'], $data['advanced'], $data['statamic_id']);

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }
}

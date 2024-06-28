<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Requests\AvailabilityCpRequest;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityCpController extends Controller
{
    public function index(string $statamic_id, ?string $property = null)
    {
        $results = Availability::where('statamic_id', $statamic_id)
            ->when($property, function (Builder $query, string $property) {
                $query->where('property', $property);
            }, function (Builder $query) {
                $query->where('property', 'none');
            })
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');

        return response()->json($results);
    }

    public function update(AvailabilityCpRequest $request)
    {
        $data = $request->validated();

        if (array_key_exists('advanced', $data)) {
            foreach ($data['advanced'] as $property) {
                $this->updateAvailability($data, $property['code']);
            }
        } else {
            $this->updateAvailability($data);
        }

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'advanced' => 'sometimes|array',
        ]);

        if (array_key_exists('advanced', $data)) {
            foreach ($data['advanced'] as $property) {
                (new Availability)->deleteForDates(
                    date_start: $data['date_start'],
                    date_end: $data['date_end'],
                    statamic_id: $data['statamic_id'],
                    advanced: [$property['code']]
                );
            }
        } else {
            (new Availability)->deleteForDates(
                date_start: $data['date_start'],
                date_end: $data['date_end'],
                statamic_id: $data['statamic_id'],
                advanced: ['none']
            );
        }

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    private function updateAvailability(array $data, ?string $property = null)
    {
        $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
        foreach ($period as $day) {
            $toUpdate = [
                'available' => $data['available'],
                'price' => $data['price'] ?? 0,
            ];

            Availability::updateOrCreate(
                [
                    'statamic_id' => $data['statamic_id'],
                    'date' => $day->isoFormat('YYYY-MM-DD'),
                    'property' => $property ?? 'none',
                ],
                [
                    'available' => $data['available'],
                    'price' => $data['price'] ?? 0,
                ]
            );
        }
    }
}

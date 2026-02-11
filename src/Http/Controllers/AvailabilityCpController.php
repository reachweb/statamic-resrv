<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Requests\AvailabilityCpRequest;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityCpController extends Controller
{
    public function index(string $statamic_id, ?string $identifier = null): JsonResponse
    {
        $results = Availability::where('statamic_id', $statamic_id)
            ->when($identifier, function (Builder $query, string $identifier) {
                $query->where('rate_id', (int) $identifier);
            })
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');

        return response()->json($results);
    }

    public function update(AvailabilityCpRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('rate_ids', $data)) {
            foreach ($data['rate_ids'] as $rateId) {
                $this->updateAvailability($data, (int) $rateId);
            }
        } else {
            $this->updateAvailability($data);
        }

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'rate_ids' => 'sometimes|array',
        ]);

        Availability::where('date', '>=', $data['date_start'])
            ->where('date', '<=', $data['date_end'])
            ->where('statamic_id', $data['statamic_id'])
            ->when(isset($data['rate_ids']), fn (Builder $query) => $query->whereIn('rate_id', $data['rate_ids']))
            ->delete();

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    private function updateAvailability(array $data, ?int $rateId = null): void
    {
        $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
        $onlyDays = $data['onlyDays'] ?? null;

        foreach ($period as $day) {
            if ($onlyDays && ! in_array($day->dayOfWeek, $onlyDays)) {
                continue;
            }

            $toUpdate = [];

            if (! is_null($data['price'])) {
                $toUpdate['price'] = $data['price'];
            }

            if (! is_null($data['available'])) {
                $toUpdate['available'] = $data['available'];
            }

            $matchKeys = [
                'statamic_id' => $data['statamic_id'],
                'date' => $day->isoFormat('YYYY-MM-DD'),
            ];

            if ($rateId) {
                $matchKeys['rate_id'] = $rateId;
            }

            Availability::updateOrCreate($matchKeys, $toUpdate);
        }
    }
}

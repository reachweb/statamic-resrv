<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Http\Requests\AvailabilityCpRequest;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;

class AvailabilityCpController extends Controller
{
    public function index(string $statamic_id, ?string $identifier = null): JsonResponse
    {
        $rate = $identifier
            ? Rate::withoutGlobalScopes()->find((int) $identifier)
            : null;

        $resolvedIdentifier = $rate && $rate->isShared() && $rate->base_rate_id
            ? (int) $rate->base_rate_id
            : ($rate?->id ?? Availability::where('statamic_id', $statamic_id)->value('rate_id'));

        $results = Availability::where('statamic_id', $statamic_id)
            ->when($resolvedIdentifier, function (Builder $query, int $rateId) {
                $query->where('rate_id', $rateId);
            })
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');

        if ($rate && $rate->isShared() && $rate->isRelative()) {
            $results = $results->map(function ($row) use ($rate) {
                $row->price = $rate->calculatePrice($row->price)->format();

                return $row;
            });
        }

        return response()->json($results);
    }

    public function update(AvailabilityCpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rateIds = $data['rate_ids'] ?? $this->defaultRateIds($data['statamic_id']);

        foreach ($rateIds as $rateId) {
            $this->updateAvailability($data, (int) $rateId);
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

        $rateIds = ! empty($data['rate_ids'])
            ? array_map(fn ($id) => AvailabilityRepository::resolveBaseRateId((int) $id), $data['rate_ids'])
            : $this->defaultRateIds($data['statamic_id']);

        Availability::where('date', '>=', $data['date_start'])
            ->where('date', '<=', $data['date_end'])
            ->where('statamic_id', $data['statamic_id'])
            ->whereIn('rate_id', $rateIds)
            ->delete();

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    private function updateAvailability(array $data, int $rateId): void
    {
        $resolvedRateId = AvailabilityRepository::resolveBaseRateId($rateId);

        // Shared rates read from the base rate's availability rows. Writing
        // prices here would overwrite the base rate's data.
        $skipPrice = ($resolvedRateId !== $rateId);

        if ($skipPrice && ! is_null($data['price']) && is_null($data['available'])) {
            abort(422, __('Price cannot be edited directly for shared rates. Edit the base rate instead.'));
        }

        DB::transaction(function () use ($data, $resolvedRateId, $skipPrice) {
            $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
            $onlyDays = $data['onlyDays'] ?? null;

            foreach ($period as $day) {
                if ($onlyDays && ! in_array($day->dayOfWeek, $onlyDays)) {
                    continue;
                }

                $toUpdate = [];

                if (! is_null($data['price']) && ! $skipPrice) {
                    $toUpdate['price'] = $data['price'];
                }

                if (! is_null($data['available'])) {
                    $toUpdate['available'] = $data['available'];
                }

                if (empty($toUpdate)) {
                    continue;
                }

                Availability::updateOrCreate([
                    'statamic_id' => $data['statamic_id'],
                    'date' => $day->isoFormat('YYYY-MM-DD'),
                    'rate_id' => $resolvedRateId,
                ], $toUpdate);
            }
        });
    }

    private function defaultRateIds(string $statamicId): array
    {
        $rate = Rate::forEntry($statamicId)->first();
        if ($rate) {
            return [$rate->id];
        }

        $rate = Rate::findOrCreateDefaultForEntry($statamicId);

        return $rate ? [$rate->id] : [];
    }
}

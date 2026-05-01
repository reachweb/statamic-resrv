<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Requests\AvailabilityCpRequest;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;

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

        if ($rate && $rate->hasIndependentSharedPricing()) {
            $overrides = RatePrice::where('rate_id', $rate->id)
                ->where('statamic_id', $statamic_id)
                ->get()
                ->keyBy(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString());

            $results = $results->map(function ($row) use ($overrides) {
                $key = Carbon::parse($row->date)->toDateString();
                if ($overrides->has($key)) {
                    $row->price = Price::create($overrides->get($key)->getRawOriginal('price'))->format();
                    $row->price_override = true;
                } else {
                    $row->price = $row->price->format();
                    $row->price_override = false;
                }

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

        $requestedRateIds = ! empty($data['rate_ids'])
            ? array_map(fn ($id) => (int) $id, $data['rate_ids'])
            : $this->defaultRateIds($data['statamic_id']);

        $rateIds = array_unique(array_map(
            fn ($id) => AvailabilityRepository::resolveBaseRateId($id),
            $requestedRateIds
        ));

        // Override prices for shared+independent rates are tied to the base
        // row's date. When that base row is removed, every sibling shared+indep
        // rate hanging off the same base must lose its override too — otherwise
        // recreating the base row later silently revives the stale child
        // prices. Always sweep by base_rate_id, not by the requested ids.
        $sharedIndependentRateIds = Rate::withoutGlobalScopes()
            ->where('availability_type', 'shared')
            ->where('pricing_type', 'independent')
            ->whereIn('base_rate_id', $rateIds)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $rateIds, $sharedIndependentRateIds) {
            Availability::where('date', '>=', $data['date_start'])
                ->where('date', '<=', $data['date_end'])
                ->where('statamic_id', $data['statamic_id'])
                ->whereIn('rate_id', $rateIds)
                ->delete();

            if (! empty($sharedIndependentRateIds)) {
                RatePrice::where('date', '>=', $data['date_start'])
                    ->where('date', '<=', $data['date_end'])
                    ->where('statamic_id', $data['statamic_id'])
                    ->whereIn('rate_id', $sharedIndependentRateIds)
                    ->delete();
            }
        });

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    private function updateAvailability(array $data, int $rateId): void
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId);
        $resolvedRateId = AvailabilityRepository::resolveBaseRateId($rateId);

        $isSharedIndependent = $rate && $rate->hasIndependentSharedPricing();

        // Shared+relative rates read from the base rate's availability rows.
        // Writing prices into base rows would overwrite the base rate's data,
        // and prices on relative rates derive from the modifier — there is no
        // distinct price to set. Block direct price edits for them.
        $skipPrice = ($resolvedRateId !== $rateId) && ! $isSharedIndependent;

        if ($skipPrice && ! is_null($data['price']) && is_null($data['available'])) {
            abort(422, __('Price cannot be edited directly for shared rates. Edit the base rate instead.'));
        }

        DB::transaction(function () use ($data, $rateId, $resolvedRateId, $skipPrice, $isSharedIndependent) {
            $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
            $onlyDays = $data['onlyDays'] ?? null;

            foreach ($period as $day) {
                if ($onlyDays && ! in_array($day->dayOfWeek, $onlyDays)) {
                    continue;
                }

                $date = $day->isoFormat('YYYY-MM-DD');

                if ($isSharedIndependent) {
                    // Without a base row there is no inventory for this date —
                    // writing a price override would be orphaned and the date
                    // would still show as unavailable. Skip silently; the admin
                    // must seed base availability via the base rate first.
                    $baseRowExists = Availability::where([
                        'statamic_id' => $data['statamic_id'],
                        'date' => $date,
                        'rate_id' => $resolvedRateId,
                    ])->exists();

                    if (! $baseRowExists) {
                        continue;
                    }

                    if (! is_null($data['price'])) {
                        RatePrice::updateOrCreate([
                            'rate_id' => $rateId,
                            'statamic_id' => $data['statamic_id'],
                            'date' => $date,
                        ], [
                            'price' => $data['price'],
                        ]);
                    }
                }

                $toUpdate = [];

                if (! is_null($data['price']) && ! $skipPrice && ! $isSharedIndependent) {
                    $toUpdate['price'] = $data['price'];
                }

                if (! is_null($data['available'])) {
                    $toUpdate['available'] = $data['available'];
                }

                if (empty($toUpdate)) {
                    continue;
                }

                if ($isSharedIndependent) {
                    Availability::where([
                        'statamic_id' => $data['statamic_id'],
                        'date' => $date,
                        'rate_id' => $resolvedRateId,
                    ])->update($toUpdate);

                    continue;
                }

                Availability::updateOrCreate([
                    'statamic_id' => $data['statamic_id'],
                    'date' => $date,
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

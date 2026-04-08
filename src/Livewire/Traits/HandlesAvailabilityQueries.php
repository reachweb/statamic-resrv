<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\CutoffException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Repositories\AvailabilityRepository;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\EntryCollection;
use Statamic\Extensions\Pagination\LengthAwarePaginator;

trait HandlesAvailabilityQueries
{
    use HandlesCutoffValidation, HandlesMultisiteIds;

    public function getAvailability(Collection $data, EntryCollection|LengthAwarePaginator|null $entries = null): array
    {
        $searchData = $this->toResrvArray($data->first());

        try {
            $entryIds = $entries ? $this->getEntryIds($entries) : null;

            return app(Availability::class)->getAvailable($searchData, $entryIds);
        } catch (AvailabilityException $exception) {
            return [
                'message' => [
                    'status' => false,
                    'error' => $exception->getMessage(),
                ],
                'request' => $searchData,
            ];
        }
    }

    protected function getEntryIds(EntryCollection|LengthAwarePaginator $entries): array
    {
        $items = $entries instanceof LengthAwarePaginator
            ? collect($entries->items())
            : $entries;

        return $items->map(
            fn ($entry) => $entry->hasOrigin() ? $entry->origin()->id() : $entry->id()
        )->toArray();
    }

    public function queryBaseAvailabilityForEntry(): array
    {
        try {
            return app(Availability::class)->getAvailabilityForEntry($this->data->toResrvArray(), $this->entryId);
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());

            return [];
        }
    }

    public function queryExtraAvailabilityForEntry(): Collection
    {
        ExpireReservations::dispatchSync();

        return $this->generateDatePeriods($this->data)->map(function ($period) {
            $searchData = array_merge($period, Arr::only($this->data->toResrvArray(), ['quantity', 'rate_id']));
            try {
                $this->validateCutoffRules($searchData['date_start']);

                return app(Availability::class)->getAvailabilityForEntry($searchData, $this->entryId, expireReservations: false);
            } catch (AvailabilityException|CutoffException $exception) {
                return [
                    'message' => [
                        'status' => false,
                        'error' => $exception->getMessage(),
                    ],
                    'request' => $searchData,
                ];
            }
        });
    }

    public function queryAvailabilityForAllRates(?int $quantityOverride = null): Collection
    {
        $searchData = array_merge($this->data->toResrvArray(), ['rate_id' => 'any']);

        if ($quantityOverride !== null) {
            $searchData['quantity'] = $quantityOverride;
        }

        try {
            $result = app(Availability::class)->getAvailabilityForEntry($searchData, $this->entryId);
            $rawData = data_get($result, 'data', []);

            // Re-key by rate_id: handle both single-rate (flat array) and multi-rate (keyed array) formats
            if (isset($rawData['rate_id'])) {
                $data = collect([$rawData['rate_id'] => $rawData]);
            } else {
                $data = collect($rawData)->keyBy('rate_id');
            }

            return collect($this->entryRates)->keys()->mapWithKeys(function ($rateId) use ($data, $result) {
                if ($data->has($rateId)) {
                    return [$rateId => [
                        'data' => $data->get($rateId),
                        'request' => $result['request'],
                        'message' => ['status' => true],
                    ]];
                }

                return [$rateId => [
                    'data' => [],
                    'request' => $result['request'] ?? [],
                    'message' => ['status' => false],
                ]];
            });
        } catch (AvailabilityException $exception) {
            return collect();
        }
    }

    public function validateAvailabilityAndPrice(): void
    {
        $searchData = array_merge(['price' => data_get($this->availability, 'data.price')], $this->data->toResrvArray());

        if (! app(Availability::class)->confirmAvailabilityAndPrice($searchData, $this->entryId)) {
            throw new AvailabilityException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }
    }

    public function validateMultiAvailabilityAndPrice(Collection $selections): void
    {
        $totalQuantity = $selections->sum('quantity');
        $maxQuantity = config('resrv-config.maximum_quantity');
        if ($totalQuantity > $maxQuantity) {
            throw new AvailabilityException(
                __('You cannot reserve these many in one reservation.')
            );
        }

        // Aggregate overlapping selections (same rate + date range) so the combined
        // quantity is checked against availability, preventing overbooking.
        $aggregated = $selections->groupBy(
            fn ($s) => $s['rate_id'].'|'.$s['date_start'].'|'.$s['date_end']
        )->map(function ($group) {
            $first = $group->first();

            return array_merge($first, ['quantity' => $group->sum('quantity')]);
        });

        foreach ($aggregated as $selection) {
            $quantity = $selection['quantity'];
            $perUnitPrice = $selection['price'];

            // Selections store per-unit prices (from the quantity=1 search), but
            // confirmAvailabilityAndPrice recomputes with the full quantity internally.
            if ($quantity > 1 && ! config('resrv-config.ignore_quantity_for_prices', false)) {
                $expectedPrice = Price::create($perUnitPrice)
                    ->multiply((string) $quantity)->format();
            } else {
                $expectedPrice = $perUnitPrice;
            }

            $searchData = [
                'date_start' => $selection['date_start'],
                'date_end' => $selection['date_end'],
                'quantity' => $quantity,
                'rate_id' => $selection['rate_id'],
                'price' => $expectedPrice,
            ];

            if (! app(Availability::class)->confirmAvailabilityAndPrice($searchData, $this->entryId)) {
                throw new AvailabilityException(
                    __('This item is not available anymore or the price has changed. Please refresh and try searching again!')
                );
            }
        }

        $this->validateCrossSelectionAvailability($selections);
    }

    /**
     * Validate that the cumulative per-day demand across all cart selections
     * does not exceed available stock for any single day.
     */
    protected function validateCrossSelectionAvailability(Collection $selections): void
    {
        $demandByBaseRate = [];
        $demandByRate = [];
        $repository = app(AvailabilityRepository::class);

        foreach ($selections as $selection) {
            $rateId = $selection['rate_id'];
            $baseRateId = $repository->resolveBaseRateId($rateId);
            $start = Carbon::parse($selection['date_start']);
            $end = Carbon::parse($selection['date_end']);

            // Use exclusive end date (checkout day is not a reserved night),
            // matching the availability system's date < date_end convention.
            for ($date = $start->copy(); $date->lt($end); $date->addDay()) {
                $dateStr = $date->toDateString();
                $demandByBaseRate[$baseRateId][$dateStr] = ($demandByBaseRate[$baseRateId][$dateStr] ?? 0) + $selection['quantity'];
                $demandByRate[$rateId][$dateStr] = ($demandByRate[$rateId][$dateStr] ?? 0) + $selection['quantity'];
            }
        }

        foreach ($demandByBaseRate as $baseRateId => $dateDemands) {
            $this->validateStandardAvailabilityForDemand($baseRateId, $dateDemands);
        }

        foreach ($demandByRate as $rateId => $dateDemands) {
            $this->validateMaxAvailableForDemand($rateId, $dateDemands);
        }
    }

    /**
     * @param  array<string, int>  $dateDemands  Map of date string => cumulative quantity
     */
    protected function validateStandardAvailabilityForDemand(int $rateId, array $dateDemands): void
    {
        $resolvedRateId = app(AvailabilityRepository::class)->resolveBaseRateId($rateId);

        $dates = array_keys($dateDemands);
        $minDate = min($dates);
        $maxDate = Carbon::parse(max($dates))->addDay()->toDateString();

        $availabilities = Availability::where('statamic_id', $this->entryId)
            ->where('rate_id', $resolvedRateId)
            ->where('date', '>=', $minDate)
            ->where('date', '<', $maxDate)
            ->get(['date', 'available'])
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->date)->toDateString() => $row->available]);

        foreach ($dateDemands as $date => $demand) {
            $available = $availabilities[$date] ?? 0;

            if ($demand > $available) {
                throw new AvailabilityException(
                    __('This item is not available anymore or the price has changed. Please refresh and try searching again!')
                );
            }
        }
    }

    /**
     * Validate the cart's per-rate demand against the rate's own max_available
     * cap. Each rate is checked independently — sibling rates that share the
     * same base may have different caps, so they cannot be lumped into one
     * "pool cap" without making validation order-dependent. The base-inventory
     * check (validateStandardAvailabilityForDemand) handles the case where
     * combined sibling demand exceeds the underlying availability.
     *
     * @param  array<string, int>  $dateDemands  Map of date string => cumulative quantity for this rate
     */
    protected function validateMaxAvailableForDemand(int $rateId, array $dateDemands): void
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId, ['id', 'max_available', 'availability_type']);

        if (! $rate || ! $rate->isShared() || ! $rate->max_available) {
            return;
        }

        $dates = array_keys($dateDemands);
        $minDate = min($dates);
        $maxDate = Carbon::parse(max($dates))->addDay()->toDateString();

        $overlapping = Reservation::where('rate_id', $rateId)
            ->whereNotIn('status', ReservationStatus::terminal())
            ->where('date_start', '<', $maxDate)
            ->where('date_end', '>', $minDate)
            ->get(['quantity', 'date_start', 'date_end']);

        $overlappingChildren = ChildReservation::where('rate_id', $rateId)
            ->whereHas('parent', fn ($q) => $q->whereNotIn('status', ReservationStatus::terminal()))
            ->where('date_start', '<', $maxDate)
            ->where('date_end', '>', $minDate)
            ->get(['quantity', 'date_start', 'date_end']);

        $allOverlapping = $overlapping->concat($overlappingChildren);

        foreach ($dateDemands as $dateStr => $cartDemand) {
            $existingQuantity = $allOverlapping
                ->filter(fn ($r) => $r->date_start->toDateString() <= $dateStr && $r->date_end->toDateString() > $dateStr)
                ->sum('quantity');

            if (($existingQuantity + $cartDemand) > $rate->max_available) {
                throw new AvailabilityException(
                    __('The maximum number of bookings for this rate has been reached.')
                );
            }
        }
    }

    protected function generateDatePeriods(): Collection
    {
        $dateStart = Carbon::parse($this->data->dates['date_start']);
        $dateEnd = Carbon::parse($this->data->dates['date_end']);

        $datePeriods = collect();

        for ($i = 1; $i <= $this->extraDays; $i++) {
            $beforeStart = $dateStart->copy()->subDays($i + ($i * $this->extraDaysOffset));
            $beforeEnd = $dateEnd->copy()->subDays($i + ($i * $this->extraDaysOffset));
            $datePeriods->put('-'.$i, [
                'date_start' => $beforeStart,
                'date_end' => $beforeEnd,
            ]);

            $afterStart = $dateStart->copy()->addDays($i + ($i * $this->extraDaysOffset));
            $afterEnd = $dateEnd->copy()->addDays($i + ($i * $this->extraDaysOffset));
            $datePeriods->put('+'.$i, [
                'date_start' => $afterStart,
                'date_end' => $afterEnd,
            ]);
        }

        $datePeriods->put(0, [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ]);

        return $datePeriods->sortKeys();
    }

    public function availabilitySearchData($values): Collection
    {
        return collect($values)
            ->filter(fn ($value, $key) => Str::startsWith($key, 'resrv_search:'))
            ->reject(fn ($value) => empty($value) || ! Arr::has($value, 'dates.date_start'));
    }

    public function toResrvArray($search): array
    {
        return [
            'date_start' => $search['dates']['date_start'],
            'date_end' => $search['dates']['date_end'],
            'quantity' => $search['quantity'] ?? 1,
            'rate_id' => $search['rate'] ?? $search['rate_id'] ?? null,
        ];
    }

    public function getAvailabilityCalendar(): array
    {
        if (! $this->entry) {
            throw new AvailabilityException(__('You need to provide an entry ID to enable the availability calendar.'));
        }

        $entry = $this->getDefaultSiteEntry($this->entry)->id();

        $rateId = ($this->rates && $this->data->rate && $this->data->rate !== 'any') ? $this->data->rate : null;

        return app(Availability::class)->getAvailabilityCalendar($entry, $rateId);
    }

    public function queryAvailableDatesFromDate(): array
    {
        if (! isset($this->data->dates['date_start'])) {
            return [];
        }

        $dateStart = Carbon::parse($this->data->dates['date_start'])->format('Y-m-d');

        $rateId = ($this->data->rate && $this->data->rate !== 'any')
            ? (int) $this->data->rate
            : null;

        $showAllRates = $this->rates && ! $rateId;

        return app(Availability::class)->getAvailableDatesFromDate(
            $this->entryId,
            $dateStart,
            $this->data->quantity ?? 1,
            $rateId,
            $showAllRates,
            $this->groupByDate
        );
    }
}

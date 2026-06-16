<?php

namespace Reach\StatamicResrv\Repositories;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;

class AvailabilityRepository
{
    /** @var array<int, Rate|null> Rates loaded as the caller referenced them, keyed by requested id. */
    protected array $rateCache = [];

    protected static function groupConcat(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "string_agg({$column}::text, ',')",
            default => "group_concat({$column})",
        };
    }

    /**
     * Loads (and memoizes) a rate exactly as the caller referenced it — without global scopes so a
     * soft-deleted rate still resolves, selecting deleted_at so callers can reject trashed rates.
     */
    protected function findRequestedRate(int $rateId): ?Rate
    {
        if (! array_key_exists($rateId, $this->rateCache)) {
            $this->rateCache[$rateId] = Rate::withoutGlobalScopes()
                ->find($rateId, ['id', 'base_rate_id', 'availability_type', 'pricing_type', 'deleted_at']);
        }

        return $this->rateCache[$rateId];
    }

    public function resolveBaseRateId(int $rateId): int
    {
        $rate = $this->findRequestedRate($rateId);

        return ($rate?->base_rate_id && $rate->isShared())
            ? (int) $rate->base_rate_id
            : $rateId;
    }

    protected function applyRateFilter(Builder $query, int $rateId): void
    {
        // A soft-deleted requested rate must surface nothing. resolveBaseRateId() maps a shared rate
        // to its (possibly still-live) base id, so a deleted_at guard on the joined base row cannot
        // reject a deleted *shared* rate — reject the requested rate itself here.
        if ($this->findRequestedRate($rateId)?->trashed()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('rate_id', $this->resolveBaseRateId($rateId));
    }

    protected function applyPublishedRateFilter(Builder $query): void
    {
        $query->whereExists(function ($subQuery) {
            $subQuery->selectRaw('1')
                ->from('resrv_rates')
                ->where('resrv_rates.published', true)
                ->whereNull('resrv_rates.deleted_at')
                ->where(function ($q) {
                    $q->whereColumn('resrv_rates.id', 'resrv_availabilities.rate_id')
                        ->orWhere(function ($q2) {
                            $q2->whereColumn('resrv_rates.base_rate_id', 'resrv_availabilities.rate_id')
                                ->where('resrv_rates.availability_type', 'shared');
                        });
                });
        });
    }

    public function availableBetween(string $date_start, string $date_end, int $duration, int $quantity, ?int $rateId = null): Builder
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(statamic_id) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, min(available) as available, rate_id")
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->when(! $rateId, fn (Builder $query) => $this->applyPublishedRateFilter($query))
            ->groupBy('statamic_id', 'rate_id')
            ->havingRaw('count(statamic_id) = ?', [$duration]);
    }

    public function itemAvailableBetween(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, ?int $rateId = null): Builder
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, min(available) as available, resrv_availabilities.rate_id")
            ->join('resrv_rates', 'resrv_availabilities.rate_id', '=', 'resrv_rates.id')
            ->where('resrv_availabilities.statamic_id', $statamic_id)
            ->where('resrv_availabilities.date', '>=', $date_start)
            ->where('resrv_availabilities.date', '<', $date_end)
            ->where('resrv_availabilities.available', '>=', $quantity)
            ->whereNull('resrv_rates.deleted_at')
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->when(! $rateId, fn (Builder $query) => $this->applyPublishedRateFilter($query))
            ->groupBy('resrv_availabilities.statamic_id', 'resrv_availabilities.rate_id', 'resrv_rates.order')
            ->havingRaw('count(resrv_availabilities.date) = ?', [$duration])
            ->orderBy('resrv_rates.order');
    }

    public function itemPricesBetween(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null): Builder
    {
        $priceConcat = self::groupConcat('price');

        return Availability::selectRaw("{$priceConcat} as prices, statamic_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->groupBy('statamic_id');
    }

    /**
     * Validates that every required day in date_start..date_end has a priced availability row.
     *
     * $onlyDays (0=Sun..6=Sat) limits the required days to those weekdays, matching the controller
     * which only writes those weekdays. End date is INCLUSIVE (unlike the exclusive booking-engine
     * queries) to match the full range a CP user types. Subset semantics: a date is covered when a
     * matching priced row exists, rather than comparing row counts. Every CP caller passes a concrete
     * $rateId — the exact rate the controller will write — so the check is always rate-scoped; the
     * nullable $rateId (an unscoped, any-rate check) is retained for the general form but unused here.
     *
     * @param  array<int, int|string>|null  $onlyDays
     */
    public function itemsExistAndHavePrices(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null, ?array $onlyDays = null): bool
    {
        $requiredDates = collect(CarbonPeriod::create($date_start, $date_end))
            ->when($onlyDays, fn (SupportCollection $dates) => $dates->filter(fn ($day) => in_array($day->dayOfWeek, $onlyDays)))
            ->map(fn ($day) => $day->toDateString());

        // onlyDays intersects the range to nothing: reject rather than report a vacuous success.
        if ($requiredDates->isEmpty()) {
            return false;
        }

        $pricedDates = Availability::query()
            ->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->whereNotNull('price')
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->get(['date'])
            ->map(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString())
            ->flip();

        return $requiredDates->every(fn ($date) => $pricedDates->has($date));
    }

    public function itemGetRates(string $statamic_id)
    {
        return Availability::select('rate_id')
            ->where('statamic_id', $statamic_id)
            ->groupBy('rate_id')
            ->get();
    }

    public function itemAvailableBetweenForAllRates(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id): Builder
    {
        $priceConcat = self::groupConcat('resrv_availabilities.price');
        $dateConcat = self::groupConcat('resrv_availabilities.date');

        return Availability::selectRaw("count(resrv_availabilities.date) as days, {$priceConcat} as prices, {$dateConcat} as dates, resrv_availabilities.statamic_id, min(resrv_availabilities.available) as available, resrv_availabilities.rate_id")
            ->join('resrv_rates', 'resrv_availabilities.rate_id', '=', 'resrv_rates.id')
            ->where('resrv_availabilities.statamic_id', $statamic_id)
            ->where('resrv_availabilities.date', '>=', $date_start)
            ->where('resrv_availabilities.date', '<', $date_end)
            ->where('resrv_availabilities.available', '>=', $quantity)
            ->whereNull('resrv_rates.deleted_at')
            ->groupBy('resrv_availabilities.statamic_id', 'resrv_availabilities.rate_id')
            ->havingRaw('count(resrv_availabilities.date) = ?', [$duration]);
    }

    public function decrement(string $date_start, string $date_end, int $quantity, string $statamic_id, ?int $rateId, int $reservationId, bool $isChildReservation = false): void
    {
        if ($rateId) {
            $rate = Rate::findOrFail($rateId);

            if ($rate->isShared()) {
                $this->decrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId, $isChildReservation);

                return;
            }
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId, $isChildReservation) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $rateId);

            foreach ($availabilities as $availability) {
                if ($availability->available < $quantity) {
                    throw new AvailabilityException(__('Not enough availability for this rate.'));
                }
            }

            $this->addToPending($availabilities, $reservationId, $quantity, $isChildReservation);
        });
    }

    public function increment(string $date_start, string $date_end, int $quantity, string $statamic_id, ?int $rateId, int $reservationId, bool $isChildReservation = false): void
    {
        if ($rateId) {
            $rate = Rate::findOrFail($rateId);

            if ($rate->isShared()) {
                $this->incrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId, $isChildReservation);

                return;
            }
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId, $isChildReservation) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $rateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity, $isChildReservation);
        });
    }

    public function delete(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null): int
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->delete();
    }

    protected function decrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId, bool $isChildReservation = false): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $rate, $reservationId, $isChildReservation) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $baseRateId);

            foreach ($availabilities as $availability) {
                if ($availability->available < $quantity) {
                    throw new AvailabilityException(__('Not enough availability for this rate.'));
                }
            }

            $this->validateMaxAvailableForDateRange($rate, $date_start, $date_end, $reservationId, $quantity, $isChildReservation);

            $this->addToPending($availabilities, $reservationId, $quantity, $isChildReservation);
        });
    }

    protected function incrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId, bool $isChildReservation = false): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $reservationId, $isChildReservation) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $baseRateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity, $isChildReservation);
        });
    }

    /** @return Collection<int, Availability> */
    protected function getLockedAvailabilities(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($rateId, fn (Builder $query, int $rateId) => $query->where('rate_id', $rateId))
            ->lockForUpdate()
            ->get();
    }

    /**
     * Prefixes the id with 'r' or 'c' so normal and child reservations (independent sequences)
     * don't collide in the pending list.
     */
    protected function pendingKey(int $reservationId, bool $isChildReservation): string
    {
        return ($isChildReservation ? 'c' : 'r').$reservationId;
    }

    protected function addToPending($availabilities, int $reservationId, int $quantity, bool $isChildReservation = false): void
    {
        $key = $this->pendingKey($reservationId, $isChildReservation);

        foreach ($availabilities as $availability) {
            $pending = $availability->pending ?? [];

            if (in_array($key, $pending, true)) {
                Log::error("Reservation key $key was already found in pending list for availability ID {$availability->id}");

                continue;
            }

            $availability->update([
                'available' => $availability->available - $quantity,
                'pending' => array_merge($pending, [$key]),
            ]);
        }
    }

    protected function removeFromPending($availabilities, int $reservationId, int $quantity, bool $isChildReservation = false): void
    {
        $key = $this->pendingKey($reservationId, $isChildReservation);

        foreach ($availabilities as $availability) {
            $pending = $availability->pending ?? [];

            // Fall back to legacy bare-integer key for entries written before namespacing,
            // so in-flight reservations still restore their stock after an upgrade.
            $position = array_search($key, $pending, true);

            if ($position === false) {
                $position = array_search($reservationId, $pending, true);
            }

            if ($position === false) {
                Log::error("Reservation key $key not found in pending list for availability ID {$availability->id}");

                continue;
            }

            unset($pending[$position]);

            $availability->update([
                'available' => $availability->available + $quantity,
                'pending' => array_values($pending),
            ]);
        }
    }

    public function validateMaxAvailable(int $rateId, string $dateStart, string $dateEnd, int $quantity): void
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId, ['id', 'max_available', 'availability_type']);

        if (! $rate || ! $rate->isShared() || ! $rate->max_available) {
            return;
        }

        $this->validateMaxAvailableForDateRange($rate, $dateStart, $dateEnd, null, $quantity, false, false);
    }

    public function checkMaxAvailable(int $rateId, string $dateStart, string $dateEnd, int $quantity): bool
    {
        try {
            $this->validateMaxAvailable($rateId, $dateStart, $dateEnd, $quantity);

            return true;
        } catch (AvailabilityException) {
            return false;
        }
    }

    public function getExhaustedDatesForRate(Rate $rate, int $quantity = 1, ?string $rangeStart = null, ?string $rangeEnd = null): SupportCollection
    {
        if (! $rate->isShared() || ! $rate->max_available) {
            return collect();
        }

        $result = $this->getExhaustedDatesForRates(collect([$rate]), $quantity, $rangeStart, $rangeEnd);

        return $result->get($rate->id, collect());
    }

    /**
     * @return SupportCollection<int, SupportCollection<int, string>> keyed by rate ID
     *
     * $rangeStart/$rangeEnd bound the lookup to an exclusive [rangeStart, rangeEnd) window so only
     * overlapping reservations are loaded; when null the lookup is unbounded.
     */
    public function getExhaustedDatesForRates(SupportCollection $rates, int $quantity = 1, ?string $rangeStart = null, ?string $rangeEnd = null): SupportCollection
    {
        $sharedRates = $rates->filter(fn (Rate $rate) => $rate->isShared() && $rate->max_available);

        if ($sharedRates->isEmpty()) {
            return collect();
        }

        $rateIds = $sharedRates->pluck('id');

        $overlapping = Reservation::whereIn('rate_id', $rateIds)
            ->whereNotIn('status', ReservationStatus::terminal())
            ->when($rangeEnd, fn ($q) => $q->where('date_start', '<', $rangeEnd))
            ->when($rangeStart, fn ($q) => $q->where('date_end', '>', $rangeStart))
            ->get(['rate_id', 'quantity', 'date_start', 'date_end']);

        $overlappingChildren = ChildReservation::whereIn('rate_id', $rateIds)
            ->whereHas('parent', fn ($q) => $q->whereNotIn('status', ReservationStatus::terminal()))
            ->when($rangeEnd, fn ($q) => $q->where('date_start', '<', $rangeEnd))
            ->when($rangeStart, fn ($q) => $q->where('date_end', '>', $rangeStart))
            ->get(['rate_id', 'quantity', 'date_start', 'date_end']);

        $allByRate = $overlapping->concat($overlappingChildren)->groupBy('rate_id');

        // Callers only ever read dates inside the requested [rangeStart, rangeEnd) window, so clamp
        // each reservation to it before expanding — a short request overlapping a months-long booking
        // would otherwise build hundreds of unused per-day entries. A null bound means unbounded on
        // that side (no clamp). This mirrors the clamp in validateMaxAvailableForDateRange().
        $windowStart = $rangeStart ? Carbon::parse($rangeStart) : null;
        $windowEnd = $rangeEnd ? Carbon::parse($rangeEnd) : null;

        return $sharedRates->mapWithKeys(function (Rate $rate) use ($allByRate, $quantity, $windowStart, $windowEnd) {
            $all = $allByRate->get($rate->id, collect());

            if ($all->isEmpty()) {
                return [$rate->id => collect()];
            }

            $dateCounts = [];
            foreach ($all as $res) {
                // date_end is exclusive (checkout day is free for the next guest); normalising to
                // date strings keeps day boundaries stable against any time component.
                $occupiedStart = ($windowStart && $res->date_start->lt($windowStart)) ? $windowStart : $res->date_start;
                $occupiedEnd = ($windowEnd && $res->date_end->gt($windowEnd)) ? $windowEnd : $res->date_end;

                $period = CarbonPeriod::create($occupiedStart->toDateString(), $occupiedEnd->toDateString(), CarbonPeriod::EXCLUDE_END_DATE);
                foreach ($period as $date) {
                    $dateStr = $date->toDateString();
                    $dateCounts[$dateStr] = ($dateCounts[$dateStr] ?? 0) + $res->quantity;
                }
            }

            return [$rate->id => collect($dateCounts)
                ->filter(fn ($count) => ($count + $quantity) > $rate->max_available)
                ->keys()];
        });
    }

    protected function validateMaxAvailableForDateRange(Rate $rate, string $dateStart, string $dateEnd, ?int $reservationId, int $quantity, bool $isChildReservation = false, bool $useLocks = true): void
    {
        if (! $rate->max_available) {
            return;
        }

        $excludeParentId = ($reservationId && ! $isChildReservation) ? $reservationId : null;
        $excludeChildId = ($reservationId && $isChildReservation) ? $reservationId : null;

        $overlapping = Reservation::where('rate_id', $rate->id)
            ->when($excludeParentId, fn ($q) => $q->where('id', '!=', $excludeParentId))
            ->whereNotIn('status', ReservationStatus::terminal())
            ->where('date_start', '<', $dateEnd)
            ->where('date_end', '>', $dateStart)
            ->when($useLocks, fn ($q) => $q->lockForUpdate())
            ->get(['quantity', 'date_start', 'date_end']);

        $overlappingChildren = ChildReservation::where('rate_id', $rate->id)
            ->when($excludeChildId, fn ($q) => $q->where('id', '!=', $excludeChildId))
            ->whereHas('parent', function ($q) {
                $q->whereNotIn('status', ReservationStatus::terminal());
            })
            ->where('date_start', '<', $dateEnd)
            ->where('date_end', '>', $dateStart)
            ->when($useLocks, fn ($q) => $q->lockForUpdate())
            ->get(['quantity', 'date_start', 'date_end']);

        $allOverlapping = $overlapping->concat($overlappingChildren);

        // Build a date => booked-quantity map in a single pass (each reservation expanded once)
        // rather than re-filtering the whole overlapping set per requested day. This keeps the
        // work — and the lockForUpdate window when useLocks is true — minimal for long stays.
        $windowStart = Carbon::parse($dateStart);
        $windowEnd = Carbon::parse($dateEnd);

        $bookedPerDay = [];
        foreach ($allOverlapping as $reservation) {
            // Only days inside the requested [dateStart, dateEnd) window are checked below, so clamp
            // each reservation to that window before expanding: a short request overlapping a
            // months-long booking would otherwise build hundreds of unused entries and needlessly
            // widen the lockForUpdate window. date_end stays exclusive (checkout day is free), and
            // normalising to date strings keeps day boundaries stable against any time component.
            $occupiedStart = $reservation->date_start->lt($windowStart) ? $windowStart : $reservation->date_start;
            $occupiedEnd = $reservation->date_end->gt($windowEnd) ? $windowEnd : $reservation->date_end;

            $occupied = CarbonPeriod::create(
                $occupiedStart->toDateString(),
                $occupiedEnd->toDateString(),
                CarbonPeriod::EXCLUDE_END_DATE
            );
            foreach ($occupied as $date) {
                $dateStr = $date->toDateString();
                $bookedPerDay[$dateStr] = ($bookedPerDay[$dateStr] ?? 0) + $reservation->quantity;
            }
        }

        $period = CarbonPeriod::create($dateStart, $dateEnd, CarbonPeriod::EXCLUDE_END_DATE);

        foreach ($period as $date) {
            $activeQuantity = $bookedPerDay[$date->toDateString()] ?? 0;

            if (($activeQuantity + $quantity) > $rate->max_available) {
                throw new AvailabilityException(__('The maximum number of bookings for this rate has been reached.'));
            }
        }
    }
}

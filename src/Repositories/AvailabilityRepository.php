<?php

namespace Reach\StatamicResrv\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;

class AvailabilityRepository
{
    /**
     * Get the appropriate group concatenation function for the database driver.
     */
    protected static function groupConcat(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "string_agg({$column}::text, ',')",
            default => "group_concat({$column})",
        };
    }

    /**
     * Resolve shared rate IDs to their base rate IDs.
     * Non-shared rates are returned as-is.
     */
    public function resolveBaseRateIds(array $rateIds): array
    {
        if (empty($rateIds)) {
            return $rateIds;
        }

        $rates = Rate::withoutGlobalScopes()
            ->whereIn('id', $rateIds)
            ->get(['id', 'base_rate_id', 'availability_type']);

        return collect($rateIds)->map(function ($id) use ($rates) {
            $rate = $rates->firstWhere('id', $id);

            return ($rate?->isShared() && $rate->base_rate_id)
                ? $rate->base_rate_id
                : $id;
        })->all();
    }

    /**
     * Resolve a single rate ID: if shared, return its base_rate_id.
     */
    public function resolveBaseRateId(int $rateId): int
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId, ['id', 'base_rate_id', 'availability_type']);

        return ($rate?->isShared() && $rate->base_rate_id)
            ? (int) $rate->base_rate_id
            : $rateId;
    }

    protected function applyAdvancedRateFilter(Builder $query, array $advanced): void
    {
        if (! in_array('any', $advanced) && ! in_array('none', $advanced)) {
            $query->whereIn('rate_id', $this->resolveBaseRateIds($advanced));
        }
    }

    public function availableBetween(string $date_start, string $date_end, int $duration, int $quantity, array $advanced)
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(statamic_id) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, rate_id")
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, fn (Builder $query, array $advanced) => $this->applyAdvancedRateFilter($query, $advanced))
            ->groupBy('statamic_id', 'rate_id')
            ->havingRaw('count(statamic_id) = ?', [$duration]);
    }

    public function itemAvailableBetween(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, array $advanced)
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, rate_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, fn (Builder $query, array $advanced) => $this->applyAdvancedRateFilter($query, $advanced))
            ->groupBy('statamic_id', 'rate_id')
            ->havingRaw('count(date) = ?', [$duration]);
    }

    public function itemPricesBetween(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        $priceConcat = self::groupConcat('price');

        return Availability::selectRaw("{$priceConcat} as prices, statamic_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->when($advanced, fn (Builder $query, array $advanced) => $this->applyAdvancedRateFilter($query, $advanced))
            ->groupBy('statamic_id');
    }

    public function itemsExistAndHavePrices(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        $result = Availability::selectRaw('COUNT(*) as total_days, SUM(CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END) as days_with_prices')
            ->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, fn (Builder $query, array $advanced) => $this->applyAdvancedRateFilter($query, $advanced))
            ->first();

        $totalDays = (int) $result->total_days;
        $daysWithPrices = (int) $result->days_with_prices;
        $expectedDays = (int) Carbon::parse($date_start)->diffInDays(Carbon::parse($date_end)->addDay(), true);

        return $totalDays > 0 && $totalDays === $daysWithPrices && $totalDays === $expectedDays;
    }

    public function itemGetRates(string $statamic_id)
    {
        return Availability::select('rate_id')
            ->where('statamic_id', $statamic_id)
            ->groupBy('rate_id')
            ->get();
    }

    public function itemAvailableBetweenForAllProperties(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id)
    {
        return $this->itemAvailableBetweenForAllRates($date_start, $date_end, $duration, $quantity, $statamic_id);
    }

    public function decrement(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced, int $reservationId)
    {
        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $advanced, $reservationId) {
            $availabilities = Availability::where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('statamic_id', $statamic_id)
                ->when($advanced, function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced) && ! in_array('none', $advanced)) {
                        $query->whereIn('rate_id', $this->resolveBaseRateIds($advanced));
                    }
                })
                ->sharedLock()
                ->get();

            $this->addToPending($availabilities, $reservationId, $quantity);
        });
    }

    public function increment(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced, int $reservationId): void
    {
        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $advanced, $reservationId) {
            $availabilities = Availability::where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('statamic_id', $statamic_id)
                ->when($advanced, function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced) && ! in_array('none', $advanced)) {
                        $query->whereIn('rate_id', $this->resolveBaseRateIds($advanced));
                    }
                })
                ->sharedLock()
                ->get();

            $this->removeFromPending($availabilities, $reservationId, $quantity);
        });
    }

    public function availableBetweenForRate(string $date_start, string $date_end, int $duration, int $quantity, ?int $rateId): Builder
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(statamic_id) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, rate_id")
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($rateId, function (Builder $query, int $rateId) {
                $query->where('rate_id', $this->resolveBaseRateId($rateId));
            })
            ->groupBy('statamic_id', 'rate_id')
            ->havingRaw('count(statamic_id) = ?', [$duration]);
    }

    public function itemAvailableBetweenForRate(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, ?int $rateId): Builder
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, max(rate_id) as rate_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($rateId, function (Builder $query, int $rateId) {
                $query->where('rate_id', $this->resolveBaseRateId($rateId));
            })
            ->groupBy('statamic_id')
            ->havingRaw('count(date) = ?', [$duration]);
    }

    public function itemPricesBetweenForRate(string $date_start, string $date_end, string $statamic_id, ?int $rateId): Builder
    {
        $priceConcat = self::groupConcat('price');

        return Availability::selectRaw("{$priceConcat} as prices, statamic_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->when($rateId, function (Builder $query, int $rateId) {
                $query->where('rate_id', $this->resolveBaseRateId($rateId));
            })
            ->groupBy('statamic_id');
    }

    public function itemAvailableBetweenForAllRates(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id): Builder
    {
        $priceConcat = self::groupConcat('resrv_availabilities.price');
        $dateConcat = self::groupConcat('resrv_availabilities.date');

        return Availability::selectRaw("count(resrv_availabilities.date) as days, {$priceConcat} as prices, {$dateConcat} as dates, resrv_availabilities.statamic_id, max(resrv_availabilities.available) as available, resrv_availabilities.rate_id")
            ->join('resrv_rates', 'resrv_availabilities.rate_id', '=', 'resrv_rates.id')
            ->where('resrv_availabilities.statamic_id', $statamic_id)
            ->where('resrv_availabilities.date', '>=', $date_start)
            ->where('resrv_availabilities.date', '<', $date_end)
            ->where('resrv_availabilities.available', '>=', $quantity)
            ->where('resrv_rates.published', true)
            ->whereNull('resrv_rates.deleted_at')
            ->groupBy('resrv_availabilities.statamic_id', 'resrv_availabilities.rate_id')
            ->havingRaw('count(resrv_availabilities.date) = ?', [$duration]);
    }

    public function decrementForRate(string $date_start, string $date_end, int $quantity, string $statamic_id, int $rateId, int $reservationId): void
    {
        $rate = Rate::findOrFail($rateId);

        if ($rate->isShared()) {
            $this->decrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId);

            return;
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilitiesForRate($date_start, $date_end, $statamic_id, $rateId);

            $this->addToPending($availabilities, $reservationId, $quantity);
        });
    }

    public function incrementForRate(string $date_start, string $date_end, int $quantity, string $statamic_id, int $rateId, int $reservationId): void
    {
        $rate = Rate::findOrFail($rateId);

        if ($rate->isShared()) {
            $this->incrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId);

            return;
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilitiesForRate($date_start, $date_end, $statamic_id, $rateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity);
        });
    }

    public function decrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $rate, $reservationId) {
            $availabilities = $this->getLockedAvailabilitiesForRate($date_start, $date_end, $statamic_id, $baseRateId);

            foreach ($availabilities as $availability) {
                if ($availability->available < $quantity) {
                    throw new AvailabilityException(__('Not enough availability for this rate.'));
                }

                $this->validateMaxAvailable($rate, $availability, $reservationId, $quantity);
            }

            $this->addToPending($availabilities, $reservationId, $quantity);
        });
    }

    public function incrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilitiesForRate($date_start, $date_end, $statamic_id, $baseRateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity);
        });
    }

    public function delete(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, fn (Builder $query, array $advanced) => $this->applyAdvancedRateFilter($query, $advanced))
            ->delete();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Availability> */
    protected function getLockedAvailabilitiesForRate(string $date_start, string $date_end, string $statamic_id, int $rateId)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->where('rate_id', $rateId)
            ->sharedLock()
            ->get();
    }

    protected function addToPending($availabilities, int $reservationId, int $quantity): void
    {
        foreach ($availabilities as $availability) {
            $pending = $availability->pending ?? [];

            if (in_array($reservationId, $pending)) {
                Log::error("Reservation ID $reservationId was already found in pending list for availability ID {$availability->id}");

                continue;
            }

            $availability->update([
                'available' => $availability->available - $quantity,
                'pending' => array_merge($pending, [$reservationId]),
            ]);
        }
    }

    protected function removeFromPending($availabilities, int $reservationId, int $quantity): void
    {
        foreach ($availabilities as $availability) {
            $pending = $availability->pending ?? [];

            if (! in_array($reservationId, $pending)) {
                Log::error("Reservation ID $reservationId not found in pending list for availability ID {$availability->id}");

                continue;
            }

            $availability->update([
                'available' => $availability->available + $quantity,
                'pending' => array_values(array_diff($pending, [$reservationId])),
            ]);
        }
    }

    protected function validateMaxAvailable(Rate $rate, Availability $availability, int $reservationId, int $quantity): void
    {
        if (! $rate->max_available) {
            return;
        }

        $activeQuantity = Reservation::where('rate_id', $rate->id)
            ->where('id', '!=', $reservationId)
            ->whereNotIn('status', ['completed', 'cancelled', 'refunded', 'expired'])
            ->where('date_start', '<=', $availability->date)
            ->where('date_end', '>', $availability->date)
            ->sum('quantity');

        if (($activeQuantity + $quantity) > $rate->max_available) {
            throw new AvailabilityException(__('The maximum number of bookings for this rate has been reached.'));
        }
    }
}

<?php

namespace Reach\StatamicResrv\Repositories;

use Carbon\Carbon;
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
    protected array $rateCache = [];

    protected static function groupConcat(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "string_agg({$column}::text, ',')",
            default => "group_concat({$column})",
        };
    }

    public function resolveBaseRateId(int $rateId): int
    {
        if (isset($this->rateCache[$rateId])) {
            return $this->rateCache[$rateId];
        }

        $rate = Rate::withoutGlobalScopes()->find($rateId, ['id', 'base_rate_id', 'availability_type', 'pricing_type']);

        $resolved = ($rate?->base_rate_id && $rate->isShared())
            ? (int) $rate->base_rate_id
            : $rateId;

        return $this->rateCache[$rateId] = $resolved;
    }

    protected function applyRateFilter(Builder $query, int $rateId): void
    {
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

        return Availability::selectRaw("count(statamic_id) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, rate_id")
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

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, rate_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
            ->when(! $rateId, fn (Builder $query) => $this->applyPublishedRateFilter($query))
            ->groupBy('statamic_id', 'rate_id')
            ->havingRaw('count(date) = ?', [$duration]);
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

    public function itemsExistAndHavePrices(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null): bool
    {
        $result = Availability::selectRaw('COUNT(*) as total_days, SUM(CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END) as days_with_prices')
            ->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($rateId, fn (Builder $query, int $rateId) => $this->applyRateFilter($query, $rateId))
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
            ->whereNull('resrv_rates.deleted_at')
            ->groupBy('resrv_availabilities.statamic_id', 'resrv_availabilities.rate_id')
            ->havingRaw('count(resrv_availabilities.date) = ?', [$duration]);
    }

    public function decrement(string $date_start, string $date_end, int $quantity, string $statamic_id, ?int $rateId, int $reservationId): void
    {
        if ($rateId) {
            $rate = Rate::findOrFail($rateId);

            if ($rate->isShared()) {
                $this->decrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId);

                return;
            }
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $rateId);

            $this->addToPending($availabilities, $reservationId, $quantity);
        });
    }

    public function increment(string $date_start, string $date_end, int $quantity, string $statamic_id, ?int $rateId, int $reservationId): void
    {
        if ($rateId) {
            $rate = Rate::findOrFail($rateId);

            if ($rate->isShared()) {
                $this->incrementShared($date_start, $date_end, $quantity, $statamic_id, $rate, $reservationId);

                return;
            }
        }

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $rateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $rateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity);
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

    protected function decrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $rate, $reservationId) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $baseRateId);

            foreach ($availabilities as $availability) {
                if ($availability->available < $quantity) {
                    throw new AvailabilityException(__('Not enough availability for this rate.'));
                }
            }

            $this->validateMaxAvailableForDateRange($rate, $date_start, $date_end, $reservationId, $quantity);

            $this->addToPending($availabilities, $reservationId, $quantity);
        });
    }

    protected function incrementShared(string $date_start, string $date_end, int $quantity, string $statamic_id, Rate $rate, int $reservationId): void
    {
        $baseRateId = $rate->base_rate_id ?? $rate->id;

        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $baseRateId, $reservationId) {
            $availabilities = $this->getLockedAvailabilities($date_start, $date_end, $statamic_id, $baseRateId);

            $this->removeFromPending($availabilities, $reservationId, $quantity);
        });
    }

    /** @return Collection<int, Availability> */
    protected function getLockedAvailabilities(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($rateId, fn (Builder $query, int $rateId) => $query->where('rate_id', $rateId))
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

    public function validateMaxAvailable(int $rateId, string $dateStart, string $dateEnd, int $quantity): void
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId, ['id', 'max_available', 'availability_type']);

        if (! $rate || ! $rate->isShared() || ! $rate->max_available) {
            return;
        }

        $this->validateMaxAvailableForDateRange($rate, $dateStart, $dateEnd, null, $quantity);
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

    public function getExhaustedDatesForRate(Rate $rate, int $quantity = 1): SupportCollection
    {
        if (! $rate->isShared() || ! $rate->max_available) {
            return collect();
        }

        $overlapping = Reservation::where('rate_id', $rate->id)
            ->whereNotIn('status', ReservationStatus::terminal())
            ->get(['quantity', 'date_start', 'date_end']);

        $overlappingChildren = ChildReservation::where('rate_id', $rate->id)
            ->whereHas('parent', fn ($q) => $q->whereNotIn('status', ReservationStatus::terminal()))
            ->get(['quantity', 'date_start', 'date_end']);

        $all = $overlapping->concat($overlappingChildren);

        if ($all->isEmpty()) {
            return collect();
        }

        $dateCounts = [];
        foreach ($all as $res) {
            $period = Carbon::parse($res->date_start)->daysUntil(Carbon::parse($res->date_end));
            foreach ($period as $date) {
                $dateStr = $date->toDateString();
                $dateCounts[$dateStr] = ($dateCounts[$dateStr] ?? 0) + $res->quantity;
            }
        }

        return collect($dateCounts)
            ->filter(fn ($count) => ($count + $quantity) > $rate->max_available)
            ->keys();
    }

    protected function validateMaxAvailableForDateRange(Rate $rate, string $dateStart, string $dateEnd, ?int $reservationId, int $quantity): void
    {
        if (! $rate->max_available) {
            return;
        }

        $overlapping = Reservation::where('rate_id', $rate->id)
            ->when($reservationId, fn ($q) => $q->where('id', '!=', $reservationId))
            ->whereNotIn('status', ReservationStatus::terminal())
            ->where('date_start', '<', $dateEnd)
            ->where('date_end', '>', $dateStart)
            ->get(['quantity', 'date_start', 'date_end']);

        $overlappingChildren = ChildReservation::where('rate_id', $rate->id)
            ->when($reservationId, fn ($q) => $q->where('id', '!=', $reservationId))
            ->whereHas('parent', function ($q) {
                $q->whereNotIn('status', ReservationStatus::terminal());
            })
            ->where('date_start', '<', $dateEnd)
            ->where('date_end', '>', $dateStart)
            ->get(['quantity', 'date_start', 'date_end']);

        $allOverlapping = $overlapping->concat($overlappingChildren);

        $period = Carbon::parse($dateStart)->daysUntil(Carbon::parse($dateEnd));

        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $activeQuantity = $allOverlapping
                ->filter(fn ($r) => $r->date_start <= $dateStr && $r->date_end > $dateStr)
                ->sum('quantity');

            if (($activeQuantity + $quantity) > $rate->max_available) {
                throw new AvailabilityException(__('The maximum number of bookings for this rate has been reached.'));
            }
        }
    }
}

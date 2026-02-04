<?php

namespace Reach\StatamicResrv\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Models\Availability;

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

    public function availableBetween(string $date_start, string $date_end, int $duration, int $quantity, array $advanced)
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(statamic_id) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, property")
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id', 'property')
            ->havingRaw('count(statamic_id) = ?', [$duration]);
    }

    public function itemAvailableBetween(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, array $advanced)
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, max(property) as property")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id')
            ->havingRaw('count(date) = ?', [$duration]);
    }

    public function itemPricesBetween(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        $priceConcat = self::groupConcat('price');

        return Availability::selectRaw("{$priceConcat} as prices, statamic_id")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id');
    }

    public function itemsExistAndHavePrices(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        // Use a single query with conditional count to avoid double querying
        $result = Availability::selectRaw('COUNT(*) as total_days, SUM(CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END) as days_with_prices')
            ->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->first();

        $totalDays = (int) $result->total_days;
        $daysWithPrices = (int) $result->days_with_prices;
        $expectedDays = (int) Carbon::parse($date_start)->diffInDays(Carbon::parse($date_end)->addDay(), true);

        return $totalDays > 0 && $totalDays === $daysWithPrices && $totalDays === $expectedDays;
    }

    public function itemGetProperties(string $statamic_id)
    {
        return Availability::select('property')
            ->where('statamic_id', $statamic_id)
            ->groupBy('property')
            ->get();
    }

    public function itemAvailableBetweenForAllProperties(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id)
    {
        $priceConcat = self::groupConcat('price');
        $dateConcat = self::groupConcat('date');

        return Availability::selectRaw("count(date) as days, {$priceConcat} as prices, {$dateConcat} as dates, statamic_id, max(available) as available, property")
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->groupBy('statamic_id', 'property')
            ->havingRaw('count(date) = ?', [$duration]);
    }

    public function decrement(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced, int $reservationId)
    {
        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $advanced, $reservationId) {
            $availabilities = Availability::where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('statamic_id', $statamic_id)
                ->when($advanced, function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->sharedLock()
                ->get();

            foreach ($availabilities as $availability) {
                $pending = $availability->pending ?? [];

                if (in_array($reservationId, $pending)) {
                    Log::error("Reservation ID $reservationId was already found in pending list for availability ID {$availability->id}");

                    continue;
                }

                $available = $availability->available - $quantity;
                $availability->update([
                    'available' => $available,
                    'pending' => array_merge($pending, [$reservationId]),
                ]);
            }
        });
    }

    public function increment(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced, int $reservationId): void
    {
        DB::transaction(function () use ($date_start, $date_end, $quantity, $statamic_id, $advanced, $reservationId) {
            $availabilities = Availability::where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('statamic_id', $statamic_id)
                ->when($advanced, function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->sharedLock()
                ->get();

            foreach ($availabilities as $availability) {
                $pending = $availability->pending ?? [];

                if (! in_array($reservationId, $pending)) {
                    Log::error("Reservation ID $reservationId not found in pending list for availability ID {$availability->id}");

                    continue;
                }

                $available = $availability->available + $quantity;
                $availability->update([
                    'available' => $available,
                    'pending' => array_values(array_diff($pending, [$reservationId])),
                ]);
            }
        });
    }

    public function delete(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->delete();
    }
}

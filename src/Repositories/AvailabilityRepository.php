<?php

namespace Reach\StatamicResrv\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityRepository
{
    public function availableBetween(string $date_start, string $date_end, int $duration, int $quantity, array $advanced)
    {
        return Availability::selectRaw('count(statamic_id) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, property')
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id', 'property')
            ->having('days', '=', $duration);
    }

    public function itemAvailableBetween(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, array $advanced)
    {
        return Availability::selectRaw('count(date) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, max(property) as property')
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
            ->having('days', '=', $duration);
    }

    public function itemPricesBetween(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        return Availability::selectRaw('group_concat(price) as prices, statamic_id')
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

    public function itemGetProperties(string $statamic_id)
    {
        return Availability::select('property')
            ->where('statamic_id', $statamic_id)
            ->groupBy('property')
            ->get();
    }

    public function decrement(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced)
    {
        $availabilities = Availability::where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->get();

        foreach ($availabilities as $availability) {
            $available = $availability->available - $quantity;
            $availability->update(['available' => $available]);
        }
    }

    public function increment(string $date_start, string $date_end, int $quantity, string $statamic_id, array $advanced): void
    {
        $availabilities = Availability::where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->get();

        foreach ($availabilities as $availability) {
            $available = $availability->available + $quantity;
            $availability->update(['available' => $available]);
        }
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

<?php

namespace Reach\StatamicResrv\Repositories;

use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityRepository
{
    public function availableBetween($date_start, $date_end, $duration, $quantity, $advanced)
    {
        if ($advanced) {
            return $this->query($advanced)
                ->selectRaw('count(statamic_id) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, property')
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('available', '>=', $quantity)
                ->when($advanced, function ($query, $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->groupBy('statamic_id', 'property')
                ->having('days', '=', $duration);
        } else {
            return $this->query($advanced)
                ->selectRaw('count(statamic_id) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available')
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('available', '>=', $quantity)
                ->groupBy('statamic_id')
                ->having('days', '=', $duration);
        }
    }

    public function itemAvailableBetween($date_start, $date_end, $duration, $quantity, $advanced, $statamic_id)
    {
        if ($advanced) {
            return $this->query($advanced)
                ->selectRaw('count(date) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, max(property) as property')
                ->where('statamic_id', $statamic_id)
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('available', '>=', $quantity)
                ->when($advanced, function ($query, $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->groupBy('statamic_id')
                ->having('days', '=', $duration);
        } else {
            return $this->query($advanced)
                ->selectRaw('count(date) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available')
                ->where('statamic_id', $statamic_id)
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->where('available', '>=', $quantity)
                ->groupBy('statamic_id')
                ->having('days', '=', $duration);
        }
    }

    public function itemPricesBetween($date_start, $date_end, $advanced, $statamic_id)
    {
        if ($advanced) {
            return $this->query($advanced)
                ->selectRaw('group_concat(price) as prices, statamic_id')
                ->where('statamic_id', $statamic_id)
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->when($advanced, function ($query, $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->groupBy('statamic_id');
        } else {
            return $this->query($advanced)
                ->selectRaw('group_concat(price) as prices, statamic_id')
                ->where('statamic_id', $statamic_id)
                ->where('date', '>=', $date_start)
                ->where('date', '<', $date_end)
                ->groupBy('statamic_id');
        }
    }

    public function decrement($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                $query->whereIn('property', $advanced);
            })
            ->decrement('available', $quantity);
    }

    public function increment($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                $query->whereIn('property', $advanced);
            })
            ->increment('available', $quantity);
    }

    public function delete($date_start, $date_end, $advanced, $statamic_id)
    {
        
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                $query->whereIn('property', $advanced);
            })
            ->delete();
    }

    public function query($advanced)
    {
        if (! $advanced) {
            return app(Availability::class);
        }

        return app(AdvancedAvailability::class);
    }
}

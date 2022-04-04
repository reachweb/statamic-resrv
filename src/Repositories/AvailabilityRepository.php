<?php

namespace Reach\StatamicResrv\Repositories;

use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityRepository
{
    public function availableBetween($date_start, $date_end, $quantity, $advanced)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function availableAt($date_start, $date_end, $quantity, $advanced)
    {
        return $this->query($advanced)
            ->where(function ($query) use ($date_start, $date_end) {
                $query->where('date', $date_start)
                ->orWhere('date', $date_end);
            })
            ->where('available', '>=', $quantity)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function itemAvailableBetween($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->where('available', '>=', $quantity)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function itemAvailableAt($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where(function ($query) use ($date_start, $date_end) {
                $query->where('date', $date_start)
                ->orWhere('date', $date_end);
            })
            ->where('statamic_id', $statamic_id)
            ->where('available', '>=', $quantity)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function priceForDates($date_start, $date_end, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function priceAtDates($date_start, $date_end, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where(function ($query) use ($date_start, $date_end) {
                $query->where('date', $date_start)
                ->orWhere('date', $date_end);
            })
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                if ($advanced !== 'any') {
                    $query->where('property', $advanced);
                }
            });
    }

    public function decrement($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        return $this->query($advanced)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function ($query, $advanced) {
                $query->where('property', $advanced);
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
                $query->where('property', $advanced);
            })
            ->increment('available', $quantity);
    }

    public function query($advanced)
    {
        if (! $advanced) {
            return app(Availability::class);
        }

        return app(AdvancedAvailability::class);
    }
}

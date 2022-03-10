<?php

namespace Reach\StatamicResrv\Repositories;

use Reach\StatamicResrv\Models\Availability; 

class AvailabilityRepository
{
    public $query;

    public function availableBetween($date_start, $date_end, $quantity)
    {
        return $this->query()
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity);
    }    
    
    public function itemAvailableBetween($date_start, $date_end, $quantity, $statamic_id)
    {
        return $this->query()
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->where('available', '>=', $quantity);
    }

    public function priceForDates($date_start, $date_end, $statamic_id)
    {
        return $this->query()
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id);
    }

    public function decrement($date_start, $date_end, $quantity, $statamic_id)
    {
        return $this->query()
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->decrement('available', $quantity);
    }
        
    public function increment($date_start, $date_end, $quantity, $statamic_id)
    {
        return $this->query()
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('statamic_id', $statamic_id)
            ->increment('available', $quantity);
    }
    
    public function query()
    {
        return app(Availability::class);
    }
}
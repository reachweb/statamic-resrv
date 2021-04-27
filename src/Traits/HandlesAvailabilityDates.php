<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;
use Reach\StatamicResrv\Exceptions\AvailabilityDurationException;

trait HandlesAvailabilityDates
{
    protected $date_start;
    protected $date_end;
    protected $duration;
    protected $invalid = false;

    protected function useTime()
    {   
        return config('resrv-config.calculate_days_using_time');
    }

    protected function checkDurationValidity()
    {
        if ($this->duration > config('resrv-config.maximum_reservation_period_in_days')) {
            throw new AvailabilityDurationException(401);
        }
        if ($this->duration < config('resrv-config.minimum_reservation_period_in_days')) {
            throw new AvailabilityDurationException(402);
        }
    }

    public function initiateAvailability($dates)
    {
        $date_start = new Carbon($dates['date_start']);
        $date_end = new Carbon($dates['date_end']);

        $difference = $date_start->diffInDays($date_end);

        // If we charge extra for using over a 24hour day, add an extra day here.
        if ($this->useTime()) {
            $floatDifference = $date_start->floatDiffInDays($date_end);
            if ($floatDifference > $difference) {
                $date_end = $date_end->add(1, 'day');
            }
        }

        $this->duration = $date_start->diffInDays($date_end);
        $this->date_start = $date_start->isoFormat('YYYY-MM-DD');
        $this->date_end = $date_end->isoFormat('YYYY-MM-DD');        
        $this->dates_initiated = true;
        $this->checkDurationValidity();
    }
}
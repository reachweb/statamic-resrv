<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;

trait HandlesAvailabilityDates
{
    protected $date_start;
    protected $date_end;
    protected $duration;
    protected $dates_initiated = false;

    protected function useTime()
    {   
        return config('resrv-config.calculate_days_using_time');
    }

    public function initiateAvailability($dates)
    {
        if ($this->dates_initiated == true) {
            return;
        }
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

        $this->date_start = $date_start->isoFormat('YYYY-MM-DD');
        $this->date_end = $date_end->isoFormat('YYYY-MM-DD');
        $this->duration = $date_start->diffInDays($date_end);
        $this->dates_initiated = true;
    }
}
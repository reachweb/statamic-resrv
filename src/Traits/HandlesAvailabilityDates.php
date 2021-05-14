<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;
use Reach\StatamicResrv\Exceptions\AvailabilityException;

trait HandlesAvailabilityDates
{
    protected $date_start;
    protected $date_end;
    protected $duration;

    protected function useTime()
    {   
        return config('resrv-config.calculate_days_using_time');
    }

    protected function checkDurationValidity()
    {
        if ($this->duration > config('resrv-config.maximum_reservation_period_in_days')) {
            throw new AvailabilityException(__('The period you selected exceeds the maximum allowed reservation period.'));
        }
        if ($this->duration < config('resrv-config.minimum_reservation_period_in_days')) {
            throw new AvailabilityException(__('The period you selected is smaller than the minimum allowed reservation period.'));
        }
    }

    protected function checkMinimumDate($date_start)
    {
        if (config('resrv-config.minimum_days_before')) {
            if ($date_start->diffInDays(Carbon::now()->startOfDay()) < config('resrv-config.minimum_days_before')) {
                throw new AvailabilityException(__('Your pickup date is closer than allowed.'));
            }
        }
    }

    public function initiateAvailability($dates)
    {
        $date_start = new Carbon($dates['date_start']);
        $date_end = new Carbon($dates['date_end']);

        if ($date_start > $date_end) {
            throw new AvailabilityException(__('Your pickup date is before the drop-off date.'));
        }

        if ($date_start < Carbon::now() || $date_end < Carbon::now()) {
            throw new AvailabilityException(__('Your pickup date is before the actual date and time.'));
        }

        $this->checkMinimumDate($date_start);

        // If we charge extra for using over a 24hour day, add an extra day here.
        if ($this->useTime()) {
            $time_start = ($date_start->hour * 60) + $date_start->minute;
            $time_end = ($date_end->hour * 60) + $date_end->minute;
            if ($time_end > $time_start) {
                $date_end = $date_end->add(1, 'day');
            }
        }

        $this->duration = $date_start->startOfDay()->diffInDays($date_end->startOfDay());
        $this->date_start = $date_start->isoFormat('YYYY-MM-DD');
        $this->date_end = $date_end->isoFormat('YYYY-MM-DD');        
        $this->dates_initiated = true;
        $this->checkDurationValidity();
    }
}
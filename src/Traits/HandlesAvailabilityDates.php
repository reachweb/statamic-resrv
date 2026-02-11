<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Reach\StatamicResrv\Exceptions\AvailabilityException;

trait HandlesAvailabilityDates
{
    protected $date_start;

    protected $date_end;

    protected $duration;

    protected $quantity;

    protected $advanced;

    protected $rateId;

    protected $round_trip;

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
        $minimumDays = config('resrv-config.minimum_days_before');

        if ($minimumDays <= 0) {
            return;
        }

        $daysUntilStart = (int) $date_start->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay(), true);

        if ($daysUntilStart < $minimumDays) {
            throw new AvailabilityException(__('Your pickup date is closer than allowed.'));
        }
    }

    private function setQuantity($data)
    {
        $quantity = Arr::get($data, 'quantity', 1);

        if ($quantity > config('resrv-config.maximum_quantity')) {
            throw new AvailabilityException(__('You cannot reserve these many in one reservation.'));
        }

        $this->quantity = $quantity;
    }

    private function setAdvanced($data)
    {
        $advanced = Arr::get($data, 'advanced');

        $this->advanced = $advanced ? explode('|', $advanced) : ['none'];
    }

    private function setRate($data)
    {
        $rateId = Arr::get($data, 'rate_id');

        $this->rateId = $rateId ? (int) $rateId : null;
    }

    private function setDates($date_start, $date_end)
    {
        // If we charge extra for using over a 24hour day, add an extra day here.
        if ($this->useTime()) {
            $time_start = ($date_start->hour * 60) + $date_start->minute;
            $time_end = ($date_end->hour * 60) + $date_end->minute;
            if ($time_end > $time_start) {
                $date_end = $date_end->add(1, 'day');
            }
        }
        $date_start = $this->clearTime($date_start);
        $date_end = $this->clearTime($date_end);

        $this->duration = (int) $date_start->diffInDays($date_end, true);
        $this->date_start = $date_start->isoFormat('YYYY-MM-DD');
        $this->date_end = $date_end->isoFormat('YYYY-MM-DD');
        $this->dates_initiated = true;
    }

    public function initiateAvailability($data)
    {
        $date_start = Carbon::parse($data['date_start']);
        $date_end = Carbon::parse($data['date_end']);

        if ($date_start > $date_end) {
            throw new AvailabilityException(__('Your pickup date is before the drop-off date.'));
        }

        if ($this->clearTime($date_start) < Carbon::now()->startOfDay() || $this->clearTime($date_end) < Carbon::now()->startOfDay()) {
            throw new AvailabilityException(__('Your pickup date is before the actual date and time.'));
        }

        $this->checkMinimumDate($date_start);

        $this->setDates($date_start, $date_end);

        $this->setQuantity($data);

        $this->setAdvanced($data);

        $this->setRate($data);

        $this->checkDurationValidity();
    }

    // Quick method to use when extra checks are not required, will merge later
    public function initiateAvailabilityUnsafe($data)
    {
        $date_start = new Carbon($data['date_start']);
        $date_end = new Carbon($data['date_end']);

        $this->setQuantity($data);

        $this->setAdvanced($data);

        $this->setRate($data);

        $this->setDates($date_start, $date_end);
    }

    public function clearTime(Carbon $date): Carbon
    {
        return $date->copy()->startOfDay();
    }
}

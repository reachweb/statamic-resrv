<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Reach\StatamicResrv\Exceptions\AvailabilityException;

trait HandlesAvailabilityDates
{
    protected string $date_start;

    protected string $date_end;

    protected int $duration;

    protected int $quantity;

    /**
     * @var array<string>
     */
    protected array $advanced;

    protected bool $round_trip;

    protected bool $dates_initiated = false;

    protected function useTime(): bool
    {
        return config('resrv-config.calculate_days_using_time');
    }

    protected function checkDurationValidity(): void
    {
        if ($this->duration > config('resrv-config.maximum_reservation_period_in_days')) {
            throw new AvailabilityException(__('The period you selected exceeds the maximum allowed reservation period.'));
        }
        if ($this->duration < config('resrv-config.minimum_reservation_period_in_days')) {
            throw new AvailabilityException(__('The period you selected is smaller than the minimum allowed reservation period.'));
        }
    }

    protected function checkMinimumDate(Carbon $date_start): void
    {
        if (config('resrv-config.minimum_days_before') > 0) {
            $date = Carbon::create($date_start->year, $date_start->month, $date_start->day, 0, 0, 0);
            if ($date instanceof Carbon) {
                if ($date->diffInDays(Carbon::now()->startOfDay()) < config('resrv-config.minimum_days_before')) {
                    throw new AvailabilityException(__('Your pickup date is closer than allowed.'));
                }
            } else {
                throw new AvailabilityException(__('Unable to parse start date.'));
            }
        }
    }

    /**
     * @param  array<string, int|string|Carbon>  $data
     */
    private function setQuantity(array $data): void
    {
        if (! Arr::exists($data, 'quantity') || ! is_numeric($data['quantity'])) {
            $this->quantity = 1;

            return;
        }
        if ($data['quantity'] > config('resrv-config.maximum_quantity')) {
            throw new AvailabilityException(__('You cannot reserve these many in one reservation.'));
        }
        $this->quantity = (int) $data['quantity'];
    }

    /**
     * @param  array<string, int|string|Carbon>  $data
     */
    private function setAdvanced(array $data): void
    {
        if (! Arr::exists($data, 'advanced')) {
            $this->advanced = ['none'];

            return;
        }
        $this->advanced = $data['advanced'] ? explode('|', (string) $data['advanced']) : [];
    }

    private function setDates(Carbon $date_start, Carbon $date_end): void
    {
        // If we charge extra for using over a 24hour day, add an extra day here.
        if ($this->useTime()) {
            $time_start = ($date_start->hour * 60) + $date_start->minute;
            $time_end = ($date_end->hour * 60) + $date_end->minute;
            if ($time_end > $time_start) {
                $date_end = $date_end->addDay();
            }
        }

        $this->duration = $date_start->startOfDay()->diffInDays($date_end->startOfDay());
        $this->date_start = $date_start->isoFormat('YYYY-MM-DD');
        $this->date_end = $date_end->isoFormat('YYYY-MM-DD');
        $this->dates_initiated = true;
    }

    /**
     * @param  array<string, int|string|Carbon>  $data
     */
    public function initiateAvailability(array $data): void
    {
        $date_start = $data['date_start'] instanceof Carbon ? $data['date_start'] : new Carbon((string) $data['date_start']);
        $date_end = $data['date_end'] instanceof Carbon ? $data['date_end'] : new Carbon((string) $data['date_end']);

        if ($date_start > $date_end) {
            throw new AvailabilityException(__('Your pickup date is before the drop-off date.'));
        }

        if ($date_start < Carbon::now() || $date_end < Carbon::now()) {
            throw new AvailabilityException(__('Your pickup date is before the actual date and time.'));
        }

        $this->checkMinimumDate($date_start);

        $this->setDates($date_start, $date_end);

        $this->setQuantity($data);

        $this->setAdvanced($data);

        $this->checkDurationValidity();
    }

    /**
     * Quick method to use when extra checks are not required, will merge later
     *
     * @param  array<string, int|string|Carbon>  $data
     */
    public function initiateAvailabilityUnsafe(array $data): void
    {
        $date_start = $data['date_start'] instanceof Carbon ? $data['date_start'] : new Carbon((string) $data['date_start']);
        $date_end = $data['date_end'] instanceof Carbon ? $data['date_end'] : new Carbon((string) $data['date_end']);

        $this->setQuantity($data);

        $this->setAdvanced($data);

        $this->setDates($date_start, $date_end);
    }
}

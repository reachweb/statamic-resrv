<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

trait HandlesPricing
{
    public function calculateReservationTotals(): Collection
    {
        // Init totals
        $total = Price::create(0);

        $reservationTotal = $this->reservation->price;

        // Calculate totals
        $extrasTotal = $this->calculateExtraTotals();
        $optionsTotal = $this->calculateOptionTotals();

        $total = $total->add($reservationTotal, $extrasTotal, $optionsTotal);

        $payment = $this->reservation->payment;

        return collect(compact('total', 'reservationTotal', 'extrasTotal', 'optionsTotal', 'payment'));
    }

    public function calculateAvailabilityTotals($availabilityTotal): PriceClass
    {
        $total = Price::create($availabilityTotal);

        $extrasTotal = $this->calculateExtraTotals();
        $optionsTotal = $this->calculateOptionTotals();

        return $total->add($extrasTotal, $optionsTotal);
    }

    public function calculateExtraTotals()
    {
        $extrasTotal = Price::create(0);

        if ($this->enabledExtras->extras->count() > 0) {
            $extrasTotal = $extrasTotal->add(...$this->enabledExtras->extras->map(fn ($extra) => Price::create($extra['price'])->multiply($extra['quantity']))->toArray());
        }

        return $extrasTotal;
    }

    public function calculateOptionTotals()
    {
        $optionsTotal = Price::create(0);

        if ($this->enabledOptions->options->count() > 0) {
            $optionsTotal = $optionsTotal->add(...$this->enabledOptions->options
                ->map(fn ($option) => Price::create($option['price']))
                ->toArray()
            );
        }

        return $optionsTotal;
    }

    public function freeCancellationPossible(): bool
    {
        if (config('resrv-config.full_payment_after_free_cancellation') === false) {
            return true;
        }
        $freeCancellation = config('resrv-config.free_cancellation_period');
        $freeCancellationDays = false;

        if ($this instanceof \Reach\StatamicResrv\Livewire\AvailabilityResults) {
            $dateStart = Carbon::parse($this->data->dates['date_start']);
            $freeCancellationDays = Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)->diffInDays(now()->startOfDay());
        }
        if ($this instanceof \Reach\StatamicResrv\Livewire\Checkout) {
            $dateStart = Carbon::parse($this->reservation->date_start);
            $freeCancellationDays = Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)->diffInDays(now()->startOfDay());
        }
        if ($freeCancellationDays !== false && $freeCancellationDays <= $freeCancellation) {
            return false;
        }

        return true;
    }
}

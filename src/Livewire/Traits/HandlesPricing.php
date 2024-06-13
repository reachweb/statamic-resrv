<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Facades\Price;

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
}

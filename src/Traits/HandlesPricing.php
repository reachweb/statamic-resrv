<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\FixedPricing;

trait HandlesPricing
{
    protected $original_price;
    protected $reservation_price;

    protected function calculatePrice($results, $id)
    {
        $start = Price::create(0);
        $this->original_price = null;

        // Add prices
        $prices = [];
        $results->each(function ($result) use (&$prices) {
            $prices[] = $result->price;
        });
        $this->reservation_price = $start->add(...$prices);

        // If FixedPricing exists, replace the price
        if (FixedPricing::getFixedPricing($id, $this->duration)) {
            $this->reservation_price = FixedPricing::getFixedPricing($id, $this->duration);
        }

        // Apply dynamic pricing
        $this->applyDynamicPricing($id);

        if ($this->quantity > 1) {
            $this->reservation_price = $this->reservation_price->multiply($this->quantity);
        }
    }

    protected function getDynamicPricing($id)
    {
        return DynamicPricing::searchForAvailability($id, $this->reservation_price, $this->date_start, $this->date_end, $this->duration);
    }

    protected function applyDynamicPricing($id)
    {
        $dynamicPricing = $this->getDynamicPricing($id);
        if ($dynamicPricing) {
            $this->original_price = $this->reservation_price->format();
            $this->reservation_price = $dynamicPricing->apply($this->reservation_price);
        }
    }
}

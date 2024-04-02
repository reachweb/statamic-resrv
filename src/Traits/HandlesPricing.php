<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\FixedPricing;

trait HandlesPricing
{
    protected $original_price;

    protected $reservation_price;

    protected function calculatePrice($prices, $id)
    {
        $start = Price::create(0);
        $this->original_price = null;

        $this->reservation_price = $start->add(...$prices->toArray());

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

    // TODO: remove the methods above
    protected function getPrices($prices, $id)
    {
        // Convert comma separated prices to collection of Price objects
        $prices = collect(explode(',', $prices))->transform(fn ($price) => Price::create($price));
        
        $start = Price::create(0);
        $originalPrice = null;

        $reservationPrice = $start->add(...$prices->toArray());

        // If FixedPricing exists, replace the price
        if (FixedPricing::getFixedPricing($id, $this->duration)) {
            $reservationPrice = FixedPricing::getFixedPricing($id, $this->duration);
        }

        // Apply dynamic pricing
        if ($discountedPrice = $this->processDynamicPricing($reservationPrice, $id)) {
            $original_price = $reservationPrice;
            $reservation_price = $discountedPrice;
        }

        return compact('originalPrice', 'reservationPrice');
    }

    protected function processDynamicPricing($price, $id)
    {
        $dynamicPricing = $this->getDynamicPricing($id);
        if (! $dynamicPricing) {
            return false;
        }
        return $dynamicPricing->apply($price);
    }
}

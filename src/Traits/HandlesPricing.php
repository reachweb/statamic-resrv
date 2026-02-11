<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Money\Price as PriceClass;

trait HandlesPricing
{
    protected function getPrices($prices, $id): array
    {
        // Convert comma separated prices to collection of Price objects
        $pricesCollection = collect(explode(',', $prices))->transform(fn ($price) => Price::create($price));

        // If a rate_id is set and the rate is relative, apply the modifier per day
        if ($this->rateId) {
            $rate = Rate::find($this->rateId);
            if ($rate?->isRelative()) {
                $pricesCollection = $pricesCollection->transform(fn ($price) => $rate->calculatePrice($price));
            }
        }

        $start = Price::create(0);
        $originalPrice = null;

        $reservationPrice = $start->add(...$pricesCollection->toArray());

        // If FixedPricing exists, replace the price
        if ($fixedPrice = FixedPricing::getFixedPricing($id, $this->duration, $this->rateId)) {
            $reservationPrice = $fixedPrice;
        }

        // Apply dynamic pricing
        if ($discountedPrice = $this->processDynamicPricing($reservationPrice, $id)) {
            $originalPrice = $reservationPrice;
            $reservationPrice = $discountedPrice;
        }

        if ($this->quantity > 1 && ! config('resrv-config.ignore_quantity_for_prices', false)) {
            $reservationPrice = $reservationPrice->multiply($this->quantity);
            if ($originalPrice !== null) {
                $originalPrice = $originalPrice->multiply($this->quantity);
            }
        }

        return compact('originalPrice', 'reservationPrice');
    }

    protected function processDynamicPricing($price, $id)
    {
        $dynamicPricings = DynamicPricing::searchForAvailability($id, $price, $this->date_start, $this->date_end, $this->duration);
        if (! $dynamicPricings) {
            return false;
        }

        return $dynamicPricings->apply(clone $price);
    }

    protected function calculatePayment($price): PriceClass
    {
        if (is_array($price)) {
            $price = $price['reservation_price'];
        }

        return match (config('resrv-config.payment')) {
            'fixed' => Price::create(config('resrv-config.fixed_amount')),
            'percent' => $price->percent(config('resrv-config.percent_amount')),
            default => $price,
        };
    }
}

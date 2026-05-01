<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Money\Price as PriceClass;

trait HandlesPricing
{
    private ?Rate $cachedRate = null;

    private ?int $cachedRateId = null;

    protected function getRate(?int $rateId = null): ?Rate
    {
        $effectiveRateId = $rateId ?? $this->rateId;

        if ($effectiveRateId === null) {
            return null;
        }

        if ($this->cachedRateId !== $effectiveRateId) {
            $this->cachedRate = Rate::find($effectiveRateId);
            $this->cachedRateId = $effectiveRateId;
        }

        return $this->cachedRate;
    }

    protected function getPrices($prices, $id, ?int $rateId = null, ?Rate $rate = null): array
    {
        $effectiveRateId = $rateId ?? $this->rateId;
        $rate = $rate ?? ($effectiveRateId ? $this->getRate($effectiveRateId) : null);

        $pricesCollection = $this->createPricesCollection($prices);

        if ($rate?->hasIndependentSharedPricing()) {
            $resolved = $this->resolveSharedIndependentPrices($rate, $id);

            if ($resolved === null) {
                return ['originalPrice' => null, 'reservationPrice' => null];
            }

            $pricesCollection = $resolved;
        }

        // If a rate is set and is relative, apply the modifier per day
        if ($rate?->isRelative()) {
            $pricesCollection = $pricesCollection->transform(fn ($price) => $rate->calculatePrice($price));
        }

        $start = Price::create(0);
        $originalPrice = null;

        $reservationPrice = $start->add(...$pricesCollection->toArray());

        // If FixedPricing exists, replace the price
        if ($fixedPrice = FixedPricing::getFixedPricing($id, $this->duration, $effectiveRateId)) {
            $reservationPrice = $fixedPrice;
        } elseif ($rate?->isRelative() && $rate->base_rate_id) {
            if ($baseFixedPrice = FixedPricing::getFixedPricing($id, $this->duration, $rate->base_rate_id)) {
                $reservationPrice = $rate->calculateTotalPrice($baseFixedPrice, $this->duration);
            }
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

    /**
     * Returns null when require_price_override is enabled and any day in the
     * booking range has no override row — signalling the rate is unavailable
     * for the requested dates.
     */
    protected function resolveSharedIndependentPrices(Rate $rate, string $statamicId): ?Collection
    {
        $dates = collect($this->getPeriod())->map(fn ($day) => $day->toDateString());

        $overrides = RatePrice::where('rate_id', $rate->id)
            ->where('statamic_id', $statamicId)
            ->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString());

        $missingDates = $dates->reject(fn ($date) => $overrides->has($date));

        if ($rate->require_price_override && $missingDates->isNotEmpty()) {
            return null;
        }

        $basePrices = collect();
        if ($missingDates->isNotEmpty() && $rate->base_rate_id) {
            $basePrices = Availability::where('rate_id', $rate->base_rate_id)
                ->where('statamic_id', $statamicId)
                ->where('date', '>=', $this->date_start)
                ->where('date', '<', $this->date_end)
                ->get()
                ->keyBy(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString());
        }

        return $dates->map(function ($date) use ($overrides, $basePrices) {
            if ($overrides->has($date)) {
                return Price::create($overrides->get($date)->getRawOriginal('price'));
            }

            if ($basePrices->has($date)) {
                return Price::create($basePrices->get($date)->getRawOriginal('price'));
            }

            return Price::create(0);
        })->values();
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

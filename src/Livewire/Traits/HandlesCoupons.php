<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Livewire\Attributes\On;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Models\DynamicPricing;

trait HandlesCoupons
{
    public function addCoupon(string $coupon)
    {
        $data = validator(['coupon' => $coupon], ['coupon' => 'required|alpha_dash'], ['coupon' => 'The coupon code is invalid.'])->validate();

        try {
            $couponModel = DynamicPricing::searchForCoupon($data['coupon'], $this->reservation->id);
        } catch (CouponNotFoundException $exception) {
            $this->addError('coupon', $exception->getMessage());

            return;
        }
        session(['resrv_coupon' => $data['coupon']]);
        $this->coupon = $data['coupon'];
        $this->resetValidation('coupon');
        $this->dispatch('coupon-applied', $this->coupon);
    }

    public function removeCoupon()
    {
        $this->dispatch('coupon-removed', $this->coupon, true);
        session()->forget('resrv_coupon');
        $this->coupon = null;
    }

    #[On('coupon-applied'), On('coupon-removed')]
    public function updateTotals($coupon, $removeCoupon = false): void
    {
        // Get the prices after applying the coupon
        $prices = $this->getUpdatedPrices();
        // Update the reservation with the new prices
        $this->reservation->update(['price' => $prices['price'], 'payment' => $prices['payment']]);
        // Remove the caches
        unset($this->reservation);
        unset($this->extras);
        unset($this->options);
        // Update pricing
        $this->updateEnabledExtraPrices();
        $this->calculateReservationTotals();
        CouponUpdated::dispatch($this->reservation, $coupon, $removeCoupon);
    }
}

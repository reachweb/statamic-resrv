<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\AffiliateAttributionSource;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Models\DynamicPricing;

class AssociateAffiliateFromCoupon
{
    public function handle(CouponUpdated $event): void
    {
        $coupon = DynamicPricing::searchForCoupon($event->coupon, $event->reservation->id);

        if (! $coupon) {
            return;
        }

        // Get the affiliate associated with this coupon
        $affiliate = $coupon->affiliate()->first();

        if (! $affiliate) {
            return;
        }

        // If removing coupon, unassociate the affiliate — but only a coupon-sourced attribution.
        // A cookie attribution (the customer arrived through the affiliate link) must survive
        // the coupon being removed.
        if ($event->remove === true) {
            $event->reservation->affiliate()
                ->wherePivot('source', AffiliateAttributionSource::Coupon->value)
                ->detach($affiliate->id);

            return;
        }

        // Unpublished affiliates are disabled: their coupon still discounts the reservation,
        // but must not earn a commission attribution. This sits after the remove branch so
        // removing the coupon still cleans up an attribution created while published.
        if (! $affiliate->published) {
            return;
        }

        // Check if this affiliate is already associated with the reservation. This also keeps a
        // cookie-sourced attribution from being downgraded to coupon-sourced when the customer
        // enters the same affiliate's coupon.
        if ($event->reservation->affiliate()->where('affiliate_id', $affiliate->id)->exists()) {
            return;
        }

        // Associate the affiliate with the reservation. Snapshot the name/code so the report
        // keeps history even after the affiliate is deleted.
        $event->reservation->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
            'source' => AffiliateAttributionSource::Coupon->value,
            'data' => json_encode([
                'name' => $affiliate->name,
                'code' => $affiliate->code,
            ]),
        ]);
    }
}

<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Models\DynamicPricing;

class AssociateAffiliateFromCoupon
{
    public function handle(CouponUpdated $event): void
    {
        // If removing coupon, we don't need to associate affiliate
        if ($event->remove === true) {
            return;
        }

        $coupon = DynamicPricing::searchForCoupon($event->coupon, $event->reservation->id);

        if (! $coupon) {
            return;
        }

        // Get the affiliate associated with this coupon
        $affiliate = $coupon->affiliate()->first();

        if (! $affiliate) {
            return;
        }

        // Check if this affiliate is already associated with the reservation
        if ($event->reservation->affiliate()->where('affiliate_id', $affiliate->id)->exists()) {
            return;
        }

        // Associate the affiliate with the reservation
        $event->reservation->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
        ]);
    }
}

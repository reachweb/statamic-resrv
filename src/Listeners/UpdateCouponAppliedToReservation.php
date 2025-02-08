<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Models\DynamicPricing;

class UpdateCouponAppliedToReservation
{
    public function handle(CouponUpdated $event): void
    {
        if ($event->remove === true) {
            $dynamicPricing = $event->reservation->dynamicPricings()->where('resrv_reservation_dynamic_pricing.data->coupon', $event->coupon)->first();
            $event->reservation->dynamicPricings()->detach($dynamicPricing->id);

            return;
        }

        $coupon = DynamicPricing::where('coupon', $event->coupon)->first();

        // Put it last even though it may not be...
        $order = $event->reservation->dynamicPricings()
            ->select('resrv_reservation_dynamic_pricing.order')
            ->max('resrv_reservation_dynamic_pricing.order') ?? 0;

        if ($event->reservation->dynamicPricings()->where('id', $coupon->id)->count() > 0) {
            $event->reservation->dynamicPricings()->updateExistingPivot($coupon->id, [
                'data' => json_encode($coupon),
                'order' => $order + 1,
            ]);

            return;
        }

        $event->reservation->dynamicPricings()->attach($coupon->id, [
            'data' => json_encode($coupon),
            'order' => $order + 1,
        ]);
    }
}

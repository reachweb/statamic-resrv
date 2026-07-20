<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;

class AddDynamicPricingsToReservation
{
    public function handle(ReservationCreated $event)
    {
        // An overridden total was not produced by the rules; recording them would misreport reports.
        if ($event->data->skipDynamicPricings) {
            return;
        }

        if ($event->data->hasCoupon()) {
            session(['resrv_coupon' => $event->data->coupon]);
        }

        $dynamicPricingData = (new Availability)->getDynamicPricingsForReservation($event->reservation);

        if (! $dynamicPricingData) {
            return;
        }

        $dynamicPricingData->getToApply()->each(function ($pricing, $key) use ($event) {
            $event->reservation->dynamicPricings()->attach($pricing->id, ['data' => json_encode($pricing), 'order' => $key]);
        });
    }
}

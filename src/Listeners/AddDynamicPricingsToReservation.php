<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;

class AddDynamicPricingsToReservation
{
    public function handle(ReservationCreated $event)
    {
        // A manually overridden total was not produced by the dynamic pricing rules, so
        // recording them as applied would misreport the price breakdown and the reports.
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

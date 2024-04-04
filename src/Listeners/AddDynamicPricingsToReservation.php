<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Models\Availability;

class AddDynamicPricingsToReservation
{
    public function handle(ReservationConfirmed $event)
    {
        $dynamicPricingData = (new Availability)->getDynamicPricingsForReservation($event->reservation);

        if (! $dynamicPricingData) {
            return;
        }

        $dynamicPricingData->getToApply()->each(function ($pricing, $key) use ($event) {
            $event->reservation->dynamicPricings()->attach($pricing->id, ['data' => json_encode($pricing), 'order' => $key]);
        });
    }
}

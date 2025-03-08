<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;

class AddDynamicPricingsToReservation
{
    public function handle(ReservationCreated $event)
    {
        if ($event->data->hasCoupon()) {
            session('resrv_coupon', $event->data->coupon);
        }
        if ($event->reservation->isParent()) {
            $event->reservation->childs()->each(function ($child) {
                $this->saveDynamicPricingToReservation($child);
            });

            return;
        }

        $this->saveDynamicPricingToReservation($event->reservation);
    }

    protected function saveDynamicPricingToReservation($reservation)
    {
        $dynamicPricingData = app(Availability::class)->getDynamicPricingsForReservation($reservation);

        if (! $dynamicPricingData) {
            return;
        }

        $dynamicPricingData->getToApply()->each(function ($pricing, $key) use ($reservation) {
            if ($reservation instanceof ChildReservation) {
                $reservation = $reservation->parent;
            }
            $reservation->dynamicPricings()->attach($pricing->id, ['data' => json_encode($pricing), 'order' => $key]);
        });
    }
}

<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;

class AddAffiliateToReservation
{
    public function handle(ReservationCreated $event)
    {
        if ($event->data->hasAffiliate()) {
            $event->reservation->affiliate()->attach($event->data->affiliate, ['fee' => $event->data->affiliate->fee]);
        }
    }
}

<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;

class AddAffiliateToReservation
{
    public function handle(ReservationCreated $event)
    {
        if ($event->affiliate) {
            $event->reservation->affiliate()->attach($event->affiliate, ['fee' => $event->affiliate->fee]);
        }
    }
}

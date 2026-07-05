<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;

class AddReservationIdToSession
{
    public function handle(ReservationCreated $event)
    {
        // A CP-created reservation must not land in the admin's session — their next
        // frontend checkout visit would mount it from session('resrv_reservation').
        if ($event->data->viaCp) {
            return;
        }

        session(['resrv_reservation' => $event->reservation->id]);
    }
}

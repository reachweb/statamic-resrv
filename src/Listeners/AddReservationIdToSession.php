<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;

class AddReservationIdToSession
{
    public function handle(ReservationCreated $event)
    {
        session(['resrv_reservation' => $event->reservation->id]);
    }
}

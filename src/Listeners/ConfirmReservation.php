<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationConfirmed;

class ConfirmReservation
{
    public function handle(ReservationConfirmed $event)
    {
        $event->reservation->status = 'confirmed';
        $event->reservation->save();
    }
}

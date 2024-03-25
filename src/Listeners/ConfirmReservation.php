<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;

class ConfirmReservation
{
    public function handle(ReservationConfirmed $event)
    {
        ray($event);
        ray($event->reservation->status);
        $event->reservation->status = ReservationStatus::CONFIRMED->value;
        $event->reservation->save();
    }
}

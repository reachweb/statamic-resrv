<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;

class ConfirmReservation
{
    public function handle(ReservationConfirmed $event)
    {
        if ($event->reservation->status === ReservationStatus::PARTNER->value) {
            return;
        }
        $event->reservation->status = ReservationStatus::CONFIRMED->value;
        $event->reservation->save();
    }
}

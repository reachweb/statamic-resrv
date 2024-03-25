<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;

class CancelReservation
{
    public function handle(ReservationCancelled $event)
    {
        $event->reservation->status = ReservationStatus::CANCELLED->value;
        $event->reservation->save();
    }
}

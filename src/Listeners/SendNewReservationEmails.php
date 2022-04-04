<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Jobs\SendNewReservationEmails as SendEmails;

class SendNewReservationEmails
{
    public function handle(ReservationConfirmed $event)
    {
        SendEmails::dispatchAfterResponse($event->reservation);
    }
}

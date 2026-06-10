<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Jobs\SendCancelledReservationEmails as SendEmails;

class SendCancelledReservationEmails
{
    public function handle(ReservationCancelledByCustomer $event)
    {
        SendEmails::dispatchAfterResponse($event->reservation);
    }
}

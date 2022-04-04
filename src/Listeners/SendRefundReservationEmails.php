<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Jobs\SendRefundReservationEmails as SendEmails;

class SendRefundReservationEmails
{
    public function handle(ReservationRefunded $event)
    {
        SendEmails::dispatchAfterResponse($event->reservation);
    }
}

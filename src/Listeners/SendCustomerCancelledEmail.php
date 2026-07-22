<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Jobs\SendCustomerCancelledEmail as SendEmail;

class SendCustomerCancelledEmail
{
    public function handle(ReservationCancelled $event): void
    {
        SendEmail::dispatchAfterResponse($event->reservation, $event->context);
    }
}

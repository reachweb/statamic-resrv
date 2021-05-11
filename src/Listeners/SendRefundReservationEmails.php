<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationRefunded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Reach\StatamicResrv\Jobs\SendRefundReservationEmails as SendEmails;

class SendRefundReservationEmails
{
    
    public function handle(ReservationRefunded $event)
    {
        SendEmails::dispatchAfterResponse($event->reservation);
    }
}

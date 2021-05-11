<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Reach\StatamicResrv\Jobs\SendEmails;

class SendNewReservationEmails
{
    
    public function handle(ReservationConfirmed $event)
    {
        SendEmails::dispatchAfterResponse($event->reservation);
    }
}

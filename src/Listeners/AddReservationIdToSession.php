<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Reach\StatamicResrv\Models\Availability;

class AddReservationIdToSession
{
    
    public function handle(ReservationCreated $event)
    {
        session(['resrv_reservation' => $event->reservation->id]);
    }
}

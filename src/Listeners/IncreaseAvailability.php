<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationExpired;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Reach\StatamicResrv\Models\Availability;

class IncreaseAvailability
{
    protected $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    
    public function handle($event)
    {
        $this->availability->incrementAvailability($event->reservation->date_start, $event->reservation->date_end, $event->reservation->item_id);
    }
}

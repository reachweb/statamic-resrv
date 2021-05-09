<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Reach\StatamicResrv\Models\Availability;

class DecreaseAvailability
{
    protected $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    
    public function handle(ReservationCreated $event)
    {
        $this->availability->decrementAvailability($event->reservation->date_start, $event->reservation->date_end, $event->reservation->item_id);
    }
}

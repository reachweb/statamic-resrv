<?php

namespace Reach\StatamicResrv\Listeners;

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
        $this->availability->incrementAvailability($event->reservation->date_start, $event->reservation->date_end, $event->reservation->quantity, $event->reservation->item_id);
    }
}

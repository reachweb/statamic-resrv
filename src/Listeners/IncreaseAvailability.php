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
        $this->availability->incrementAvailability($event->reservation);
    }
}

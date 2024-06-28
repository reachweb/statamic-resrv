<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }
}

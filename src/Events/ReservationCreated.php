<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reservation;

    public $affiliate;

    public function __construct(Reservation $reservation, ?Affiliate $affiliate = null)
    {
        $this->reservation = $reservation;
        $this->affiliate = $affiliate;
    }
}

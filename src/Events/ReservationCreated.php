<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reservation;

    public $data;

    public function __construct(Reservation $reservation, ?ReservationData $data = null)
    {
        $this->reservation = $reservation;
        $this->data = $data ?? new ReservationData();
    }
}

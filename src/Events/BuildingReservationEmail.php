<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class BuildingReservationEmail
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Mailable $mailable,
        public ?Reservation $reservation = null,
    ) {}
}

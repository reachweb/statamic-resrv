<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Fired when a customer self-cancels a reservation from the reservation-status component,
 * after the terminal transition has committed — REFUNDED (charge returned) inside the free
 * cancellation window, CANCELLED (payment retained) outside it. Availability restore and
 * the customer email are handled by the ReservationRefunded / ReservationCancelled chains
 * dispatched in the same flow — this event only carries the "the customer did this
 * themselves" signal for the admin notification.
 */
class ReservationCancelledByCustomer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }
}

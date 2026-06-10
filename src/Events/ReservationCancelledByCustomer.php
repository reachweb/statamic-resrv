<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Fired when a customer self-cancels a reservation from the reservation-status component,
 * after the refund has gone through. Distinct from ReservationCancelled (whose listeners
 * restore availability for abandoned checkouts) and from ReservationRefunded (which already
 * fires for every refund and handles availability + the customer refund email) — this event
 * only carries the "the customer did this themselves" signal for the admin notification.
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

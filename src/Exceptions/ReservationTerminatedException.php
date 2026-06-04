<?php

namespace Reach\StatamicResrv\Exceptions;

use Reach\StatamicResrv\Enums\ReservationStatus;

/**
 * Thrown when the checkout flow encounters a reservation in a terminal non-expired state
 * (CONFIRMED, REFUNDED, PARTNER). Terminal, but semantically different from expired —
 * CONFIRMED reservations should redirect to the checkout-completed entry so the user lands
 * on a success page instead of an error page.
 */
class ReservationTerminatedException extends ReservationException
{
    public function __construct(
        public readonly ReservationStatus $terminalStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : 'Reservation is in a terminal state: '.$terminalStatus->value,
        );
    }
}

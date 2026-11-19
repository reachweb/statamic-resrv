<?php

namespace Reach\StatamicResrv\Exceptions;

use Reach\StatamicResrv\Enums\ReservationStatus;

/**
 * Thrown when the checkout flow encounters a reservation it has finished with (CONFIRMED, REFUNDED,
 * CANCELLED, PARTNER, or AWAITING_PAYMENT after an offline confirm). Terminal for the checkout,
 * but semantically different from expired —
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

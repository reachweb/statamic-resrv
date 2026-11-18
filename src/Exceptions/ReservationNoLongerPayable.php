<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

/**
 * Thrown under the row lock when the reservation can no longer be paid the way the caller set out to:
 * it left a payable state after a pay page's outer guard (the just-minted intent is already voided),
 * or its payment method changed since the caller read it. Abort quietly and re-render.
 */
class ReservationNoLongerPayable extends Exception
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('Reservation %d is no longer payable.', $reservationId));
    }
}

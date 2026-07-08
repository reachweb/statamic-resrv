<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

/**
 * Thrown when a reservation is transitioned out of a payable state (cancelled by the hold-lapse
 * sweep or a CP action, or confirmed out of band) between a pay page's outer guard and the locked
 * intent write. The just-minted intent has already been voided; the caller should abort quietly
 * and let the page re-render the reservation's current state.
 */
class ReservationNoLongerPayable extends Exception
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('Reservation %d is no longer payable.', $reservationId));
    }
}

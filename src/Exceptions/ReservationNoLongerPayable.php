<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

/**
 * Thrown when a reservation leaves a payable state between a pay page's outer guard and the
 * locked intent write. The just-minted intent is already voided; abort quietly and re-render.
 */
class ReservationNoLongerPayable extends Exception
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('Reservation %d is no longer payable.', $reservationId));
    }
}

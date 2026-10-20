<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

/**
 * Thrown inside the lapse sweep's row lock when the hold deadline moved (extended or removed)
 * after the candidate query read it — the sweep skips the row instead of cancelling it.
 */
class HoldNoLongerLapsed extends Exception
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('The hold on reservation %d no longer lapsed.', $reservationId));
    }
}

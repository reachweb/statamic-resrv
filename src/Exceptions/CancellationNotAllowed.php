<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

class CancellationNotAllowed extends Exception
{
    public function __construct(public readonly int $reservationId)
    {
        parent::__construct(sprintf('Reservation %d cannot be cancelled by the customer.', $reservationId));
    }
}

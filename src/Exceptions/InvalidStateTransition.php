<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;
use Reach\StatamicResrv\Enums\ReservationStatus;

class InvalidStateTransition extends Exception
{
    public function __construct(
        public readonly ReservationStatus $from,
        public readonly ReservationStatus $to,
        public readonly int $reservationId,
    ) {
        parent::__construct(sprintf(
            'Cannot transition reservation %d from %s to %s.',
            $reservationId,
            $from->value,
            $to->value,
        ));
    }
}

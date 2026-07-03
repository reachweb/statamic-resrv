<?php

namespace Reach\StatamicResrv\Exceptions;

use InvalidArgumentException;
use Throwable;

class UnknownPaymentGateway extends InvalidArgumentException
{
    public function __construct(public readonly string $gateway, public readonly int $reservationId, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Payment gateway [%s] recorded on reservation %d is no longer configured.', $gateway, $reservationId),
            0,
            $previous,
        );
    }
}

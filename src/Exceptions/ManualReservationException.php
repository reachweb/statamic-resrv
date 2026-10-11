<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;
use Throwable;

class ManualReservationException extends Exception
{
    /**
     * @param  ?string  $field  The create-form input the error belongs to, so the CP can show it under that field.
     * @param  ?string  $reason  Machine-readable cause the CP branches on (e.g. 'price_changed').
     */
    public function __construct(
        string $message = '',
        public readonly ?string $field = null,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

class ReservationExpired extends Exception
{
    public function __construct()
    {
        $message = 'You have exceeded the maximum time allowed to complete your reservation. Please try again.';
        parent::__construct($message);
    }
}

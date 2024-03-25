<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

class NoAdvancedAvailabilitySet extends Exception
{
    public function __construct($blueprint)
    {
        $message = "The resrv_availability field in blueprint [{$blueprint}] does not have advanced availability enabled.";
        parent::__construct($message);
    }
}

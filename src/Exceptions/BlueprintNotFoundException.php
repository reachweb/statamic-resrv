<?php

namespace Reach\StatamicResrv\Exceptions;

use Exception;

class BlueprintNotFoundException extends Exception
{
    public function __construct($blueprint)
    {
        $message = "Blueprint [{$blueprint}] not found.";
        parent::__construct($message);
    }
}

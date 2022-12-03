<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AvailabilitySearch
{
    use Dispatchable;

    public $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }
}

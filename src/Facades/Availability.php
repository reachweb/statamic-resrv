<?php

namespace Reach\StatamicResrv\Facades;

use Illuminate\Support\Facades\Facade;
use Reach\StatamicResrv\Repositories\AvailabilityRepository;

class Availability extends Facade
{
    protected static function getFacadeAccessor()
    {
        return new AvailabilityRepository();
    }
}

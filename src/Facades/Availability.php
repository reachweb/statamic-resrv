<?php

namespace Reach\StatamicResrv\Facades;

use Reach\StatamicResrv\Repositories\AvailabilityRepository;
use Illuminate\Support\Facades\Facade;

class Availability extends Facade
{
    protected static function getFacadeAccessor()
    {
        return new AvailabilityRepository();
    }
}
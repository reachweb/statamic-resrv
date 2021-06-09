<?php

namespace Reach\StatamicResrv\Facades;

use Reach\StatamicResrv\Money\Price as PriceClass;
use Illuminate\Support\Facades\Facade;

class Price extends Facade
{
    protected static function getFacadeAccessor()
    {
        return new PriceClass();
    }
}
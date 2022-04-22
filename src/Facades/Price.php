<?php

namespace Reach\StatamicResrv\Facades;

use Illuminate\Support\Facades\Facade;
use Reach\StatamicResrv\Money\Price as PriceClass;

class Price extends Facade
{
    protected static function getFacadeAccessor()
    {
        return PriceClass::class;
    }
}

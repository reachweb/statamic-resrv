<?php

namespace Reach\StatamicResrv\Facades;

use Illuminate\Support\Facades\Facade;

class ResrvHelper extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'resrvhelper';
    }
}

<?php

namespace Reach\StatamicResrv\Facades;

use Illuminate\Support\Facades\Facade;
use Reach\StatamicResrv\Helpers\AvailabilityFieldHelper;

/**
 * @method static string getHandle(\Statamic\Fields\Blueprint $blueprint)
 * @method static \Statamic\Fields\Field|null getField(\Statamic\Fields\Blueprint $blueprint)
 * @method static bool blueprintHasAvailabilityField(\Statamic\Fields\Blueprint $blueprint)
 * @method static void clearCacheForBlueprint(string $namespace) (used)
 *
 * @see AvailabilityFieldHelper
 */
class AvailabilityField extends Facade
{
    protected static function getFacadeAccessor()
    {
        return AvailabilityFieldHelper::class;
    }
}

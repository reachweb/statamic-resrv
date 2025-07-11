<?php

namespace Reach\StatamicResrv\Helpers;

use Reach\StatamicResrv\Facades\AvailabilityField;
use Statamic\Facades\Collection;

class ResrvHelper
{
    public static function collectionsWithResrv()
    {
        return Collection::all()->filter(function ($collection) {
            foreach ($collection->entryBlueprints() as $blueprint) {
                if (AvailabilityField::blueprintHasAvailabilityField($blueprint)) {
                    return true;
                }
            }
        })->map(function ($collection) {
            return [
                'title' => $collection->title(),
                'handle' => $collection->handle(),
                'advanced' => self::hasAdvanced($collection),
            ];
        });
    }

    private static function hasAdvanced($collection)
    {
        foreach ($collection->entryBlueprints() as $blueprint) {
            if (AvailabilityField::blueprintHasAvailabilityField($blueprint)) {
                $field = AvailabilityField::getField($blueprint);
                if (array_key_exists('advanced_availability', $field->config())) {
                    return true;
                }
            }
        }

        return false;
    }
}

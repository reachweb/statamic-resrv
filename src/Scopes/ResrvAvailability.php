<?php

namespace Reach\StatamicResrv\Scopes;

use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Query\Scopes\Scope;

class ResrvAvailability extends Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply($query, $values)
    {
        if (session()->missing('resrv-search')) {
            return $query;
        }

        try {
            $availability = (new Availability)->getAvailableItems(session('resrv-search'));
        } catch (AvailabilityException $exception) {
            return $query;
        }

        return $query->whereIn('id', array_keys($availability));
    }
}

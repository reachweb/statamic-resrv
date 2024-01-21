<?php

namespace Reach\StatamicResrv\Filters;

use Statamic\Query\Scopes\Filter;

class ReservationStartingDate extends Filter
{
    use FiltersByDate;

    protected static $handle = 'date_start';

    public static function title()
    {
        return __('Reservation start date');
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}

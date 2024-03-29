<?php

namespace Reach\StatamicResrv\Filters;

use Statamic\Query\Scopes\Filter;

class ReservationMadeDate extends Filter
{
    use FiltersByDate;

    protected static $handle = 'created_at';

    public static function title()
    {
        return __('Reservation made date');
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}

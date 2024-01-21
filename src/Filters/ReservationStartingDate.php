<?php

namespace Reach\StatamicResrv\Filters;

use Statamic\Query\Scopes\Filter;
use Reach\StatamicResrv\Models\Reservation;
use Carbon\Carbon;

class ReservationStartingDate extends Filter
{
    protected $pinned = true;

    protected static $title = 'Year';

    public function fieldItems()
    {
        $years = Reservation::pluck('date_start')->map(function ($date) {
            return Carbon::parse($date)->format('Y');
        })->unique()->toArray();

        return [
            'date' => [
                'type' => 'select',
                'options' => array_combine($years, $years),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $query->whereYear('date_start', $values['date']);
    }

    public function badge($values)
    {
        return $values['date'];       
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}

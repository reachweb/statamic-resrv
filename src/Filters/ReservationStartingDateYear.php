<?php

namespace Reach\StatamicResrv\Filters;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Query\Scopes\Filter;

class ReservationStartingDateYear extends Filter
{
    protected $pinned = true;

    public static function title()
    {
        return __('Year');
    }

    public function fieldItems()
    {
        // Distinct years in SQL instead of materializing every date_start row into PHP — this
        // runs on every listing page render. Year extraction has no driver-portable syntax.
        $year = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y', date_start)",
            'pgsql' => 'EXTRACT(YEAR FROM date_start)::int',
            default => 'YEAR(date_start)',
        };

        $years = Reservation::query()
            ->selectRaw("DISTINCT {$year} AS year")
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year) => (string) $year)
            ->all();

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

<?php

namespace Reach\StatamicResrv\Filters;

use Reach\StatamicResrv\Models\Reservation;
use Statamic\Entries\Entry;
use Statamic\Query\Scopes\Filter;

class ReservationEntry extends Filter
{
    protected $pinned = true;

    protected $entries;

    public function __construct()
    {
        $ids = Reservation::pluck('item_id')->unique()->toArray();

        $this->entries = Entry::query()->whereIn('id', $ids)->get()->flatMap(function ($entry) {
            return [
                $entry->id() => $entry->title,
            ];
        });
    }

    public static function title()
    {
        return __('Entry');
    }

    public function fieldItems()
    {
        return [
            'entry' => [
                'type' => 'checkboxes',
                'options' => $this->entries,
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (count($values['entry']) === 0) {
            return;
        }
        $query->whereIn('item_id', $values['entry']);
    }

    public function badge($values)
    {
        return collect($values['entry'])->map(function ($entry) {
            return $this->entries[$entry];
        })->implode(', ');
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}

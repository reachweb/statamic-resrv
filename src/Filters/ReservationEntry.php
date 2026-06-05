<?php

namespace Reach\StatamicResrv\Filters;

use Reach\StatamicResrv\Models\Reservation;
use Statamic\Entries\Entry;
use Statamic\Query\Scopes\Filter;

class ReservationEntry extends Filter
{
    protected $pinned = true;

    protected $entries;

    public static function title()
    {
        return __('Entry');
    }

    public function fieldItems()
    {
        return [
            'entry' => [
                'type' => 'checkboxes',
                'options' => $this->entries(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (empty($values['entry'])) {
            return;
        }
        $query->whereIn('item_id', $values['entry']);
    }

    public function badge($values)
    {
        return collect($values['entry'])->map(function ($entry) {
            return $this->entries()[$entry];
        })->implode(', ');
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }

    // Built lazily and deduped in SQL: Statamic constructs this filter afresh on every filtered
    // data fetch just to call apply(), which doesn't need the option list — only the index page
    // (fieldItems) and badges do.
    protected function entries()
    {
        return $this->entries ??= Entry::query()
            ->whereIn('id', Reservation::query()->distinct()->pluck('item_id')->all())
            ->get()
            ->flatMap(fn ($entry) => [$entry->id() => $entry->title]);
    }
}

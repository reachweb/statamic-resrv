<?php

namespace Reach\StatamicResrv\Resources\Concerns;

use Illuminate\Support\Collection;
use Statamic\Facades\Entry;

trait ResolvesReservationEntries
{
    /**
     * Resolves every reservation row's Statamic entry in a single stache query, keyed by id.
     *
     * The Reservation `entry` accessor calls Entry::find() per row (a stache query each), so a
     * paginated CP list/calendar would otherwise run one lookup per row. EntryRepository::find()
     * is itself just query()->where('id', ...)->first(), making a batched whereIn() semantically
     * identical while collapsing N lookups into one.
     */
    protected function resolveReservationEntries(Collection $reservations): Collection
    {
        $itemIds = $reservations->pluck('item_id')->filter()->unique()->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        return Entry::query()
            ->whereIn('id', $itemIds->all())
            ->get()
            ->keyBy(fn ($entry) => $entry->id());
    }
}

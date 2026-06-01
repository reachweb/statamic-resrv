<?php

namespace Reach\StatamicResrv\Resources\Concerns;

use Illuminate\Support\Collection;
use Statamic\Facades\Entry;

trait ResolvesReservationEntries
{
    /**
     * Batch-resolves entries for all reservation rows in one stache query (keyed by id),
     * avoiding one Entry::find() per row.
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

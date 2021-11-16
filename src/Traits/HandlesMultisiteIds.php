<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Statamic\Facades\Site;
use Statamic\Facades\Entry;

trait HandlesMultisiteIds
{
    public function getDefaultSiteEntry($statamic_id)
    {
        $entry = Entry::find($statamic_id);

        if (! $entry) {
            throw new AvailabilityException(__('An entry with this ID cannot be found.'));
        }

        if (! Site::hasMultiple() || ! $entry->hasOrigin()) {
            return $entry;
        }

        return $entry->origin();
    }

    public function getMultisiteIds($statamic_id)
    {        
        $multisiteEntries = Entry::query()->where('origin', $statamic_id)->get();
        $ids = $multisiteEntries->map(function ($item) {
            return [
                 $item->locale() => $item->id()
            ];
        });
        return $ids->values()->all();
    }
}
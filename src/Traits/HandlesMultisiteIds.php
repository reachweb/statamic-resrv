<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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
}

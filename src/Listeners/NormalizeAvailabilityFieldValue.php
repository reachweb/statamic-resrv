<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Facades\AvailabilityField;
use Statamic\Events\EntrySaved;

class NormalizeAvailabilityFieldValue
{
    /**
     * Regenerate the availability field value when it carries another entry's ID
     * (e.g. entry duplication copies the original's value).
     */
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if ($entry->hasOrigin()) {
            return;
        }

        if (! $field = AvailabilityField::getField($entry->blueprint())) {
            return;
        }

        $value = $entry->get($field->handle());

        if ($value === null || $value === 'disabled' || $value === $entry->id()) {
            return;
        }

        $entry->set($field->handle(), $entry->id())->saveQuietly();
    }
}

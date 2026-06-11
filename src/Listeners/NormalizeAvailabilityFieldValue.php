<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Facades\AvailabilityField;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Stache;

class NormalizeAvailabilityFieldValue
{
    /**
     * Reset the availability field value when it carries another entry's ID (e.g. after
     * duplication), before the write so saved-event subscribers never see it stale.
     */
    public function handle(EntrySaving $event): void
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

        // New entries only get an ID after this event; assign one early (the repository keeps it).
        if (! $entry->id()) {
            $entry->id(Stache::generateId());
        }

        $entry->set($field->handle(), $entry->id());
    }
}

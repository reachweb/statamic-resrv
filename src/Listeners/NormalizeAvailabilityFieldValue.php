<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Facades\AvailabilityField;
use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntryCreated;
use Statamic\Events\EntrySaving;

class NormalizeAvailabilityFieldValue
{
    /**
     * Reset the availability field value when it carries another entry's ID (e.g. after
     * duplication), before the write so saved-event subscribers never see it stale.
     *
     * New entries are skipped here: the repository owns ID generation (a Stache UUID, or
     * the Eloquent driver's database auto-increment), so one must not be assigned earlier.
     */
    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry->id()) {
            return;
        }

        if (! $handle = $this->staleFieldHandle($entry)) {
            return;
        }

        $entry->set($handle, $entry->id());
    }

    /**
     * Normalize new entries once the repository has assigned their ID — EntryCreated fires
     * before EntrySaved subscribers, and the quiet save persists the corrected value
     * without re-firing events.
     */
    public function handleCreated(EntryCreated $event): void
    {
        $entry = $event->entry;

        if (! $handle = $this->staleFieldHandle($entry)) {
            return;
        }

        $entry->set($handle, $entry->id())->saveQuietly();
    }

    private function staleFieldHandle(Entry $entry): ?string
    {
        if ($entry->hasOrigin()) {
            return null;
        }

        if (! $field = AvailabilityField::getField($entry->blueprint())) {
            return null;
        }

        $value = $entry->get($field->handle());

        if ($value === null || $value === 'disabled' || $value === $entry->id()) {
            return null;
        }

        return $field->handle();
    }
}

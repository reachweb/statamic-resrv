<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Support\ActiveReservationsGuard;
use Statamic\Events\EntryDeleting;

class PreventEntryDeletionWithActiveReservations
{
    public function handle(EntryDeleting $event): bool
    {
        if (! AvailabilityField::blueprintHasAvailabilityField($event->entry->blueprint())) {
            return true;
        }

        // Reservations are keyed to the origin entry, so resolve localizations to their origin first —
        // otherwise deleting a localization of a booked item would slip past the guard.
        $entryId = $event->entry->hasOrigin()
            ? $event->entry->origin()->id()
            : $event->entry->id();

        if (ActiveReservationsGuard::hasActiveReservationsForEntry($entryId)) {
            Log::warning('Resrv: blocked Statamic entry deletion (active reservations exist)', [
                'entry_id' => $entryId,
            ]);

            return false;
        }

        return true;
    }
}

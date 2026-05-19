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

        if (ActiveReservationsGuard::hasActiveReservationsForEntry($event->entry->id())) {
            Log::warning('Resrv: blocked Statamic entry deletion (active reservations exist)', [
                'entry_id' => $event->entry->id(),
            ]);

            return false;
        }

        return true;
    }
}

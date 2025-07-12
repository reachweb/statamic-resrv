<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Events\EntryDeleted as StatamicEntryDeleted;

class EntryDeleted
{
    public function handle(StatamicEntryDeleted $event)
    {
        if (! AvailabilityField::blueprintHasAvailabilityField($event->entry->blueprint())) {
            return;
        }

        $id = $event->entry->id();

        // Delete availability
        Availability::where('statamic_id', $id)->delete();

        // Delete dynamic pricing associations
        DB::table('resrv_dynamic_pricing_assignments')
            ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Availability')
            ->where('dynamic_pricing_assignment_id', $id)
            ->delete();

        Cache::forget('resrv_disabled_entry_ids');
    }
}

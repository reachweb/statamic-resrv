<?php

namespace Reach\StatamicResrv\Listeners;

use Statamic\Events\EntryDeleted as StatamicEntryDeleted;
use Reach\StatamicResrv\Models\Availability;
use Illuminate\Support\Facades\DB;

class EntryDeleted
{
    public function handle(StatamicEntryDeleted $event)
    {
        if ($event->entry->get('resrv_availability') == null) {
            return;
        }
        
        $id = $event->entry->id();        
        // Delete availability
        Availability::entry($id)->delete();

        // Delete dynamic pricing associations
        DB::table('resrv_dynamic_pricing_assignments')
            ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Availability')
            ->where('dynamic_pricing_assignment_id', $id)
            ->delete();
    }
    
}

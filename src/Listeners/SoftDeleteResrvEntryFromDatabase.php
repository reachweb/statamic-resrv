<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Events\EntryDeleted;

class SoftDeleteResrvEntryFromDatabase
{
    public function handle(EntryDeleted $event)
    {
        Entry::where('item_id', $event->entry->id())->delete();
    }
}

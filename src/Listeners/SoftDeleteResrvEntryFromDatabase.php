<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Events\EntryDeleted;

class SoftDeleteResrvEntryFromDatabase
{
    /**
     * Soft-delete the entry mirror so its primary key — and the resrv_entry_extra
     * links keyed to it — survive a delete then re-save/re-import cycle, which
     * Entry::syncToDatabase restores from the trashed row.
     *
     * Availability and dynamic-pricing rows are keyed by statamic_id (not the mirror
     * id), so the sibling EntryDeleted listener hard-deletes them; they are intentionally
     * NOT restored. A restored entry therefore comes back with its extras config but no
     * availability — stock must be re-entered.
     */
    public function handle(EntryDeleted $event): void
    {
        Entry::where('item_id', $event->entry->id())->delete();
    }
}

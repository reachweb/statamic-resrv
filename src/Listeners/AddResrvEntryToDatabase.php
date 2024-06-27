<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Events\EntrySaved;

class AddResrvEntryToDatabase
{
    public function handle(EntrySaved $event)
    {
        return (new Entry)->syncToDatabase($event->entry);
    }
}

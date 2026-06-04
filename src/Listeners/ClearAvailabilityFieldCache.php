<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Statamic\Events\BlueprintSaved;

class ClearAvailabilityFieldCache
{
    public function handle(BlueprintSaved $event): void
    {
        AvailabilityField::clearCacheForBlueprint($event->blueprint);

        // Also clear the disabled IDs cache as field changes might affect availability
        Cache::forget('resrv_disabled_entry_ids');
    }
}

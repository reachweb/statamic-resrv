<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;

trait HandlesAvailabilityHooks
{
    use HandlesAvailabilityQueries;

    protected function bootEntriesHooks($hookName, $callback)
    {
        $instance = $this;
        $callback($hookName, function ($entries, $next) use ($instance) {
            $searchData = $instance->availabilitySearchData($this->params);

            if ($searchData->isEmpty()) {
                return $next($entries);
            }

            // If live_availability stops being set on entries, uncomment cloning below.
            // Statamic's hook pipeline may require cloned entries in some versions.
            // $entries = $entries->map(fn ($entry) => clone $entry);

            $result = $instance->getAvailability($searchData, $entries);
            if (data_get($result, 'message.status') === false) {
                return $next($entries);
            }

            $entries->each(function ($entry) use ($result) {
                // In multisite, availability data is keyed by origin ID.
                $lookupId = $entry->hasOrigin() ? $entry->origin()->id() : $entry->id();

                if ($data = data_get($result, 'data.'.$lookupId, false)) {
                    $entry->set('live_availability', $data->count() === 1 ? $data->first() : $data);
                }
            });

            return $next($entries);
        });
    }
}

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

            // For some reason if we don't clone the entries, the live_availability
            // will be not be set on the original entries
            $clonedEntries = $entries->map(function ($entry) {
                return clone $entry;
            });

            $result = $instance->getAvailability($searchData, $clonedEntries);
            if (data_get($result, 'message.status') === false) {
                return $next($entries);
            }

            $entries->each(function ($entry) use ($result) {
                if ($data = data_get($result, 'data.'.$entry->id(), false)) {

                    if ($data->count() === 1) {
                        $data = $data->first();
                    }

                    $entry->set('live_availability', $data);
                }
            });

            return $next($entries);
        });
    }
}

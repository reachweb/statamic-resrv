<?php

namespace Reach\StatamicResrv\Traits;

use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
use Reach\StatamicResrv\Support\AvailabilityRequestCache;
use Reach\StatamicResrv\Support\AvailabilitySortBuilder;

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

            $sortDirective = $instance->availabilitySortDirective($this->params);
            $cache = app(AvailabilityRequestCache::class);
            $resrvSearchArray = $instance->toResrvArray($searchData->first());

            $cached = $cache->get($resrvSearchArray);
            $result = $cached['result'] ?? null;

            if ($result === null) {
                // If live_availability stops being set on entries, uncomment cloning below.
                // Statamic's hook pipeline may require cloned entries in some versions.
                // $entries = $entries->map(fn ($entry) => clone $entry);

                // Entry-restricted result — do NOT write to the shared cache. The cache
                // key only covers search params, so a second tag with the same params
                // but a different $entries set would receive a partial result. The
                // ResrvSearch scope is the sole writer; it always produces an
                // unrestricted result safe to reuse.
                $result = $instance->getAvailability($searchData, $entries);
            }

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

            // If a sort was requested but the query-time order couldn't be applied
            // (e.g. flat-file driver), reorder the augmented entries here. This is
            // only correct for non-paginated results — refuse to silently mis-sort
            // when pagination/limiting is in play.
            if ($sortDirective && empty($cached['orderApplied'])) {
                if ($instance->paginationActive($this->params)) {
                    throw new AvailabilityException(__(
                        'resrv_sort cannot be combined with paginate/limit/offset on the current entry driver — orderByRaw is not supported. Use Statamic\'s Eloquent entry driver to enable sorted pagination.'
                    ));
                }

                $entries = $instance->reorderEntriesByPrice($entries, $result, $sortDirective);
            }

            return $next($entries);
        });
    }

    public function paginationActive($params): bool
    {
        $values = is_object($params) && method_exists($params, 'all') ? $params->all() : (array) $params;

        foreach (['paginate', 'limit', 'offset', 'chunk'] as $key) {
            if (! empty($values[$key]) && $values[$key] !== false) {
                return true;
            }
        }

        return false;
    }

    public function reorderEntriesByPrice($entries, array $result, array $sortDirective)
    {
        $orderedIds = AvailabilitySortBuilder::sort($result, $sortDirective);

        if (empty($orderedIds)) {
            return $entries;
        }

        $position = array_flip(array_values(array_map('strval', $orderedIds)));

        $sorted = $entries->sortBy(function ($entry) use ($position) {
            $lookupId = (string) ($entry->hasOrigin() ? $entry->origin()->id() : $entry->id());

            return $position[$lookupId] ?? PHP_INT_MAX;
        })->values();

        // If the input is an EntryCollection, preserve its concrete class so
        // downstream Statamic code keeps working.
        $class = get_class($entries);

        if ($sorted instanceof $class) {
            return $sorted;
        }

        return new $class($sorted->all());
    }
}

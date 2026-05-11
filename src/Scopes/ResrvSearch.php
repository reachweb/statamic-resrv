<?php

namespace Reach\StatamicResrv\Scopes;

use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
use Reach\StatamicResrv\Support\AvailabilityRequestCache;
use Reach\StatamicResrv\Support\AvailabilitySortBuilder;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Query\Scopes\Scope;

class ResrvSearch extends Scope
{
    use HandlesAvailabilityQueries;

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply($query, $values)
    {
        $searchData = $this->availabilitySearchData($values);

        if ($searchData->isEmpty()) {
            return $query;
        }

        $result = $this->getAvailability($searchData);

        // TODO: throw an exception here
        if (! isset($result['data']) && $result['message']['status'] === false) {
            return $query;
        }

        $sortDirective = $this->availabilitySortDirective($values);

        $cache = app(AvailabilityRequestCache::class);
        $resrvSearchArray = $this->toResrvArray($searchData->first());

        $originIds = $sortDirective
            ? AvailabilitySortBuilder::sort($result, $sortDirective)
            : $result['data']->keys()->toArray();

        if (empty($originIds)) {
            $cache->put($resrvSearchArray, $result, [], false);

            return $query->whereIn('id', []);
        }

        // In multisite, availability is stored with origin entry IDs, but we may be
        // querying localized entries. Map origin IDs to localized IDs for the current site.
        $entryIds = $this->mapOriginIdsToCurrentSite($originIds);

        $query->whereIn('id', $entryIds);

        $orderApplied = false;

        if ($sortDirective && ! empty($entryIds)) {
            $orderApplied = $this->applyPriceOrder($query, $entryIds);
        }

        $cache->put(
            $resrvSearchArray,
            $result,
            $sortDirective ? $entryIds : null,
            $orderApplied,
        );

        return $query;
    }

    /**
     * Apply a deterministic ORDER BY based on the pre-sorted entry IDs.
     *
     * Falls back to no-op (returning false) on query builders that don't
     * support orderByRaw (e.g. Statamic's flat-file Stache driver). The
     * fetched-entries hook then reorders the augmented entries in PHP.
     */
    protected function applyPriceOrder($query, array $entryIds): bool
    {
        if (! $this->canApplyRawOrder($query)) {
            return false;
        }

        try {
            // Reset any prior order so our sort is the primary.
            $query->reorder();

            $sql = 'CASE';
            $bindings = [];

            foreach ($entryIds as $position => $entryId) {
                $sql .= ' WHEN id = ? THEN ?';
                $bindings[] = (string) $entryId;
                $bindings[] = $position;
            }

            $sql .= ' END';

            $query->orderByRaw($sql, $bindings);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function canApplyRawOrder($query): bool
    {
        if (method_exists($query, 'orderByRaw')) {
            return true;
        }

        // Statamic's EloquentQueryBuilder delegates unknown calls via __call to
        // the underlying Eloquent builder, which has orderByRaw. Check for the
        // wrapper class name string-wise to avoid coupling to the optional driver.
        $class = get_class($query);

        return str_contains($class, 'EloquentQueryBuilder') || str_contains($class, '\\Eloquent\\');
    }

    protected function mapOriginIdsToCurrentSite(array $originIds): array
    {
        // Single site: no mapping needed
        if (! Site::hasMultiple()) {
            return $originIds;
        }

        $currentSite = Site::current()->handle();
        $defaultSite = Site::default()->handle();

        // On default site, origin IDs are already correct
        if ($currentSite === $defaultSite) {
            return $originIds;
        }

        // Batch fetch localized entries for the current site in a single query.
        // Statamic stores origin references, so we query entries in the current site
        // whose origin is one of our origin IDs.
        $localizedEntries = Entry::query()
            ->where('site', $currentSite)
            ->whereIn('origin', $originIds)
            ->get()
            ->keyBy(fn ($entry) => $entry->origin()?->id());

        return collect($originIds)
            ->map(fn ($originId) => $localizedEntries->get($originId)?->id())
            ->filter()
            ->values()
            ->toArray();
    }
}

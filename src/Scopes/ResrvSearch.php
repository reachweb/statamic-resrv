<?php

namespace Reach\StatamicResrv\Scopes;

use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
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

        // Invalid searches (bad dates, over-limit quantity, lead-time violation, etc.) return no 'data' key —
        // exclude all entries rather than surfacing the full collection as bookable.
        if (! isset($result['data']) && $result['message']['status'] === false) {
            return $query->whereIn('id', []);
        }

        $originIds = $result['data']->keys()->toArray();

        if (empty($originIds)) {
            return $query->whereIn('id', []);
        }

        // In multisite, availability is stored with origin entry IDs, but we may be
        // querying localized entries. Map origin IDs to localized IDs for the current site.
        $entryIds = $this->mapOriginIdsToCurrentSite($originIds);

        return $query->whereIn('id', $entryIds);
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

        // Batch-fetch localized entries for the current site whose origin matches our IDs.
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

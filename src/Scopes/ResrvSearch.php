<?php

namespace Reach\StatamicResrv\Scopes;

use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
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

        // TODO: thow an exception here
        if (! isset($result['data']) && $result['message']['status'] === false) {
            return $query;
        }

        return $query->whereIn('id', $result['data']->keys()->toArray());
    }
}

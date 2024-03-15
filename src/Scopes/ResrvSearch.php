<?php

namespace Reach\StatamicResrv\Scopes;

use Illuminate\Support\Arr;
use Reach\StatamicResrv\Livewire\Traits\QueriesAvailability;
use Statamic\Query\Scopes\Scope;

class ResrvSearch extends Scope
{
    use QueriesAvailability;

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

        if (Arr::has($result, 'message.status') && data_get($result, 'message.status') === false) {
            return $query;
        }

        return $query->whereIn('id', array_keys($result));
    }
}

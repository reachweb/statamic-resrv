<?php

namespace Reach\StatamicResrv\Scopes;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Query\Scopes\Scope;

class ResrvSearch extends Scope
{
    public AvailabilityData $data;

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply($query, $values)
    {
        $search = collect($values)->filter(function ($value, $key) {
            return Str::startsWith($key, 'resrv_search:');
        })->first();

        try {
            $availability = (new Availability)->getAvailableItems($this->toResrvArray($search));
        } catch (AvailabilityException $exception) {
            return $query;
        }

        return $query->whereIn('id', array_keys($availability));
    }

    public function toResrvArray($search)
    {
        return [
            'date_start' => $search['dates']['date_start'],
            'date_end' => $search['dates']['date_end'],
            'quantity' => $search['quantity'] ?? 1,
            'property' => $search['property'] ?? '',
        ];
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Statamic\Facades\Entry;


class Availability extends Model
{
    use HasFactory, HandlesAvailabilityDates;

    protected $fillable = ['statamic_id', 'date', 'price', 'available'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }

    public function scopeEntry($query, $entry)
    {
        return $query->where('statamic_id', $entry);
    }

    /**
     * Search for availability entries between the dates and then return the ids
     * of the items that have at least 1 available for each day.
     */
    protected function availableForDates($dates) {

        $results = $this->where('date', '>=', $this->date_start)
            ->where('date', '<=', $this->date_end)
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date');

        $days = [];
        foreach ($results->sortBy('date') as $result) {
            if ($result['available'] > 0) {
                $days[$result['date']][] = ($result['statamic_id']);
            }            
        }

        if (count($days) == 0) {
            return [];
        }

        $disabled = $this->getDisabledIds();        
        $available = array_intersect(...array_values($days));

        return array_diff($available, $disabled);
    }

    /**
     * Gets the total price of an entry for a period of time
     */
    protected function getPriceForDates($dates, $statamic_id) {

        $results = $this->where('date', '>=', $this->date_start)
            ->where('date', '<=', $this->date_end)
            ->where('statamic_id', $statamic_id)
            ->get(['price', 'available']);

        return $this->calculatePrice($results);

    }

    /**
     * Calls two scopes: one for getting the available items and one to get the total pricing
     * of each item.
     */
    public function scopeGetAvailabilityForDates($query, $dates) {

        $this->initiateAvailability($dates);

        $availableWithPricing = [];
        $available = $this->availableForDates($dates);

        foreach ($available as $id) {
            $availableWithPricing[$id] = [
                'request' => [
                    'days' => $this->duration,
                    'date_start' => $this->date_start,
                    'date_end' => $this->date_end
                ],
                'data' => [
                    'price' => $this->getPriceForDates($dates, $id)
                ],
                'message' => [
                    'success' => count($available)
                ]
            ];
        };

        return $availableWithPricing;
    }

    protected function getDisabledIds()
    {
        $results = Entry::query()
            ->where('availability', 'disabled')
            ->where('published', true)
            ->get()
            ->toAugmentedArray('id');
        return array_flatten($results);
    }

    protected function calculatePrice(Collection $results)
    {
        return $results->sum('price');
    }
}

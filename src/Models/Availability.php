<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Statamic\Facades\Entry;


class Availability extends Model
{
    use HasFactory;

    protected $fillable = ['statamic_id', 'date', 'price', 'available'];

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
    public function scopeAvailableForDates($query, $date_start, $date_end) {
        $results = $query->where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->get(['statamic_id', 'date', 'available'])
            ->sortBy('date');

        $days = [];
        foreach ($results as $result) {
            if ($result['available'] > 0) {
                $days[$result['date']][] = ($result['statamic_id']);
            }            
        }

        $disabled = $this->getDisabledIds();        
        $available = array_intersect(...array_values($days));

        return array_diff($available, $disabled);
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
}

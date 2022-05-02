<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Reach\StatamicResrv\Database\Factories\AdvancedAvailabilityFactory;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Facades\Blueprint;

class AdvancedAvailability extends Availability
{
    use HasFactory, HandlesAvailabilityDates, HandlesMultisiteIds;

    protected $table = 'resrv_advanced_availabilities';

    protected $fillable = ['statamic_id', 'date', 'price', 'available', 'property'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return AdvancedAvailabilityFactory::new();
    }

    public function getPropertyLabel($handle, $collection, $slug)
    {
        $blueprint = Blueprint::find('collections.'.$collection.'.'.$handle);
        if (! $blueprint->hasField('resrv_availability')) {
            return false;
        }
        $properties = $blueprint->field('resrv_availability')->get('advanced_availability');
        if (array_key_exists($slug, $properties)) {
            return $properties[$slug];
        }

        return $slug;
    }

    protected function availableForDates()
    {
        $results = AvailabilityRepository::availableBetween($this->date_start, $this->date_end, $this->quantity, $this->advanced)->get();

        $idsFound = $results->groupBy('statamic_id')->keys();
        $days = [];
        foreach ($idsFound as $id) {
            $dates = $results->where('statamic_id', $id)->sortBy('date');
            $property = $dates->first()->property;
            // Remove any dates that have a different property (in case we were searching for any or multiple properties)
            $dates = $dates->reject(fn ($item) => $item->property != $property);
            // If the count of the dates is not the same like the period, it usually
            // means that a date has no availability information, so we should just skip
            if ($dates->count() !== count($this->getPeriod())) {
                continue;
            }
            foreach ($dates as $availability) {
                $days[$availability->date][] = $id;
            }
        }

        if (count($days) == 0) {
            return [];
        }

        $disabled = $this->getDisabledIds();
        $available = array_intersect(...array_values($days));

        return array_diff($available, $disabled);
    }

    public function getPriceForDates($results, $statamic_id)
    {
        $entry = $this->getDefaultSiteEntry($statamic_id);

        $results = $results->where('statamic_id', $statamic_id)->groupBy('property');

        // If we have more than one properties, return the cheapest
        if ($results->count() > 1) {
            $results = $results->sortBy(function ($property) {
                return $property->sortBy('price')->first()->price;
            });
        }

        $this->calculatePrice($results->first(), $entry->id());
        return [   
            'reservation_price' => $this->reservation_price,
            'original_price' => $this->original_price ?? null,
        ];
    }
}

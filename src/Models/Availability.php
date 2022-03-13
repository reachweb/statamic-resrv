<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Statamic\Facades\Entry;
use Carbon\CarbonPeriod;

class Availability extends Model implements AvailabilityContract
{
    use HasFactory, HandlesAvailabilityDates, HandlesMultisiteIds;

    protected $table = 'resrv_availabilities';

    protected $fillable = ['statamic_id', 'date', 'price', 'available'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }

    public function scopeEntry($query, $entry)
    {
        return $query->where('statamic_id', $entry);
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }
    
    public function getAvailableItems($data) 
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getAllAvailableItems();
    }
    
    public function getAvailabilityForItem($data, $statamic_id) 
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getSpecificItem($statamic_id);
    }
    
    public function confirmAvailabilityAndPrice($data, $statamic_id) 
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItem($statamic_id);

        if ($availability['message']['status'] != 1) {
            return false;
        }

        if ($availability['data']['price'] != $data['price']) {            
            return false;
        }

        return true;
        
    }

    public function decrementAvailability($date_start, $date_end, $quantity, $statamic_id) 
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
        ]);

        AvailabilityRepository::decrement($this->date_start, $this->date_end, $this->quantity, $this->advanced, $statamic_id);
    }  
    
    public function incrementAvailability($date_start, $date_end, $quantity, $statamic_id) 
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
        ]); 

        AvailabilityRepository::increment($this->date_start, $this->date_end, $this->quantity, $this->advanced, $statamic_id);
    }  

    protected function getAllAvailableItems()
    {
        $availableWithPricing = [];
        $available = $this->availableForDates();

        foreach ($available as $id) {
            $price = $this->getPriceForDates($id);

            // Apply dynamic pricing here (fixed already applied on getPriceForDates)
            $dynamicPricing = $this->getDynamicPricing($id, $price);
            if ($dynamicPricing) {
                $originalPrice = $price;
                $price = $dynamicPricing->apply($price);
            }

            // Multiply for quantity here
            if ($this->quantity > 1) {
                $price->multiply($this->quantity);
            }

            $availableWithPricing[$id] = [
                'id' => $id,    
                'request' => [
                    'days' => $this->duration,
                    'date_start' => $this->date_start,
                    'date_end' => $this->date_end,
                    'quantity' => $this->quantity
                ],
                'data' => [
                    'price' => $price->format(),
                    'payment' => $this->calculatePayment($price)->format(),
                    'original_price' => (isset($originalPrice) ? $originalPrice->format() : null)
                ],
                'message' => [
                    'status' => count($available)
                ]
            ];

            $multisiteIds = $this->getMultisiteIds($id);
            if (count($multisiteIds) > 0) {
                $availableWithPricing[$id]['multisite_ids'] = $multisiteIds;
            }
        };
        
        return $availableWithPricing;
    }

    protected function getSpecificItem($statamic_id)
    {
        $entry = $this->getDefaultSiteEntry($statamic_id);
        
        $results = AvailabilityRepository::itemAvailableBetween($this->date_start, $this->date_end, $this->quantity, $this->advanced, $entry->id())
            ->get(['date', 'price', 'available'])
            ->sortBy('date');

        $entryAvailabilityValue = $entry->get('resrv_availability');

        if ($results->count() !== count($this->getPeriod()) || $entryAvailabilityValue == 'disabled') {
            return [
                'message' => [
                    'status' => false
                ]
            ];
        }

        $price = $this->calculatePrice($results);        

        if (FixedPricing::getFixedPricing($entry->id(), $this->duration)) {
            $price = FixedPricing::getFixedPricing($entry->id(), $this->duration);
        }

        $dynamicPricing = $this->getDynamicPricing($entry->id(), $price);
        $originalPrice = null;
        if ($dynamicPricing) {
            $originalPrice = $price;
            $price = $dynamicPricing->apply($price);
        }

        // Multiply for quantity here
        if ($this->quantity > 1) {
            $price->multiply($this->quantity);
        }

        return $this->buildSpecificItemArray($price, $originalPrice);   
    }

    protected function buildSpecificItemArray($price, $originalPrice)
    {
        return [
            'request' => [
                'days' => $this->duration,
                'date_start' => $this->date_start,
                'date_end' => $this->date_end,
                'quantity' => $this->quantity
            ],
            'data' => [
                'price' => $price->format(),
                'payment' => $this->calculatePayment($price)->format(),
                'original_price' => (isset($originalPrice) ? $originalPrice->format() : null)
            ],
            'message' => [
                'status' => 1
            ]
        ];     
    }

    /**
     * Search for availability entries between the dates and then return the ids
     * of the items that have at least 1 available for each day.
     */
    protected function availableForDates() {

        $results = AvailabilityRepository::availableBetween($this->date_start, $this->date_end, $this->quantity, $this->advanced)->get();

        $idsFound = $results->groupBy('statamic_id')->keys();

        $days = [];
        foreach ($idsFound as $id) {
            $dates = $results->where('statamic_id', $id)->sortBy('date');
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

    /**
     * Gets the total price of an entry for a period of time
     */
    protected function getPriceForDates($statamic_id) {

        if (FixedPricing::getFixedPricing($statamic_id, $this->duration)) {
            return FixedPricing::getFixedPricing($statamic_id, $this->duration);
        }

        $results = AvailabilityRepository::priceForDates($this->date_start, $this->date_end, $this->advanced, $statamic_id)
            ->get(['price', 'available']);
        return $this->calculatePrice($results);

    }

    protected function getDisabledIds()
    {
        $results = Entry::query()
            ->where('resrv_availability', 'disabled')
            ->where('published', true)
            ->get()
            ->toAugmentedArray('id');
        return array_flatten($results);
    }

    protected function calculatePrice(Collection $results): PriceClass
    {
        $first = $results->first();
        if ($results->count() == 0) {
            return $first->price;
        }
        $prices = array();
        foreach ($results as $index => $result) {
            if ($index == 0) {
                continue;
            }
            $prices[] = $result->price;
        }
        $result = $first->price->add(...$prices);

        return $result;
    }

    protected function getPeriod()
    {
        return CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE);
    }

    protected function calculatePayment($price): PriceClass
    {
        if (config('resrv-config.payment', 'full') == 'full') {
            return $price;
        }
        if (config('resrv-config.payment') == 'fixed') {
            return Price::create(config('resrv-config.fixed_amount'));
        }
        if (config('resrv-config.payment') == 'percent') {
            return $price->percent(config('resrv-config.percent_amount'));
        }
    }

    protected function getDynamicPricing($id, $price)
    {
        return DynamicPricing::searchForAvailability($id, $price, $this->date_start, $this->date_end, $this->duration);        
    }

}

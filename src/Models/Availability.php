<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Statamic\Facades\Entry;
use Carbon\CarbonPeriod;

class Availability extends Model
{
    use HasFactory, HandlesAvailabilityDates;

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
    
    public function scopeGetAvailabilityForDates($scope, $dates, $statamic_id = null) { 
        
        ExpireReservations::dispatch();

        $this->initiateAvailability($dates);

        if (! $statamic_id) {
            return $this->getAllAvailableItems();
        }

        if ($statamic_id) {
            return $this->getSpecificItem($statamic_id);
        }
        
    }

    public function confirmAvailabilityAndPrice($data, $statamic_id) {

        ExpireReservations::dispatch();

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

    public function decrementAvailability($date_start, $date_end, $statamic_id) 
    {
        $this->initiateAvailability([
            'date_start' => $date_start,
            'date_end' => $date_end,
        ]); 

        $this->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->where('statamic_id', $statamic_id)
            ->decrement('available');
    }  
    
    public function incrementAvailability($date_start, $date_end, $statamic_id) 
    {
        $this->initiateAvailability([
            'date_start' => $date_start,
            'date_end' => $date_end,
        ]); 

        $this->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->where('statamic_id', $statamic_id)
            ->increment('available');
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

            $availableWithPricing[$id] = [
                'request' => [
                    'days' => $this->duration,
                    'date_start' => $this->date_start,
                    'date_end' => $this->date_end
                ],
                'data' => [
                    'price' => $price,
                    'payment' => $this->calculatePayment($price),
                    'original_price' => (isset($originalPrice) ? $originalPrice : null)
                ],
                'message' => [
                    'status' => count($available)
                ]
            ];
        };

        return $availableWithPricing;
    }

    protected function getSpecificItem($statamic_id)
    {
        $results = $this->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->where('statamic_id', $statamic_id)
            ->get(['date', 'price', 'available'])
            ->sortBy('date');

        if ($results->contains('available', 0) || $results->count() !== count($this->getPeriod())) {
            return [
                'message' => [
                    'status' => false
                ]
            ];
        }

        $price = $this->calculatePrice($results);        

        if (FixedPricing::getFixedPricing($statamic_id, $this->duration)) {
            $price = FixedPricing::getFixedPricing($statamic_id, $this->duration)->format();
        }

        $dynamicPricing = $this->getDynamicPricing($statamic_id, $price);
        if ($dynamicPricing) {
            $originalPrice = $price;
            $price = $dynamicPricing->apply($price);
        }

        return [
            'request' => [
                'days' => $this->duration,
                'date_start' => $this->date_start,
                'date_end' => $this->date_end
            ],
            'data' => [
                'price' => $price,
                'payment' => $this->calculatePayment($price),
                'original_price' => (isset($originalPrice) ? $originalPrice : null)
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

        $results = $this->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->get(['statamic_id', 'date', 'price', 'available']);

        $idsFound = $results->groupBy('statamic_id')->keys();

        $days = [];
        foreach ($idsFound as $id) {
            $dates = $results->where('statamic_id', $id)->sortBy('date');
            // If the count of the dates is not the same like the period, it usually 
            // means that a date has no availability information, so we should just skip
            if ($dates->count() !== count($this->getPeriod())) {
                continue;
            }
            // Also continue of there us a day with availability 0
            if ($dates->contains('available', 0)) {
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
            return FixedPricing::getFixedPricing($statamic_id, $this->duration)->format();
        }

        $results = $this->where('date', '>=', $this->date_start)
            ->where('date', '<', $this->date_end)
            ->where('statamic_id', $statamic_id)
            ->get(['price', 'available']);

        return $this->calculatePrice($results);

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
        $first = $results->first();
        if ($results->count() == 0) {
            return $first->price->format();
        }
        $prices = array();
        foreach ($results as $index => $result) {
            if ($index == 0) {
                continue;
            }
            $prices[] = $result->price;
        }
        $result = $first->price->add(...$prices);
        return $result->format();
    }

    protected function getPeriod()
    {
        return CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE);
    }

    protected function calculatePayment($price)
    {
        if (config('resrv-config.payment', 'full') == 'full') {
            return Price::create($price)->format();
        }
        if (config('resrv-config.payment') == 'fixed') {
            return Price::create(config('resrv-config.fixed_amount'))->format();
        }
        if (config('resrv-config.payment') == 'percent') {
            $totalPrice = Price::create($price);
            return $totalPrice->percent(config('resrv-config.percent_amount'))->format();
        }
    }

    protected function getDynamicPricing($id, $price)
    {
        return DynamicPricing::searchForAvailability($id, $price, $this->date_start, $this->date_end, $this->duration);
        
    }

}

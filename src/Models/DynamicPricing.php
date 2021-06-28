<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\DynamicPricingFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Traits\HandlesComparisons;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Statamic\Facades\Entry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class DynamicPricing extends Model
{
    use HasFactory, HandlesComparisons, HandlesOrdering;

    protected $table = 'resrv_dynamic_pricing';

    protected $fillable = ['title', 'amount_type', 'amount_operation', 'amount', 'date_start', 'date_end', 'date_include', 'condition_type', 'condition_comparison', 'condition_value', 'order'];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
    ];

    protected static function newFactory()
    {
        return DynamicPricingFactory::new();
    }

    public $toApply;

    public function getAmount($value)
    {
        if ($this->amount_type == 'percent') {
            return $value;
        }
        return Price::create($value);
    }

    public function extras()
    {
        return $this->morphedByMany(
            Extra::class, 
            'dynamic_pricing_assignment', 
            'resrv_dynamic_pricing_assignments', 
            'dynamic_pricing_id', 
            'dynamic_pricing_assignment_id'
        );
    }
    
    public function entries()
    {
        return $this->morphedByMany(
            Availability::class, 
            'dynamic_pricing_assignment', 
            'resrv_dynamic_pricing_assignments', 
            'dynamic_pricing_id', 
            'dynamic_pricing_assignment_id'
        );
    }

    public function apply($price)
    {
        $newPrice = Price::create($price);
        foreach ($this->toApply as $policy) {
            $method = $policy->amount_type;
            $newPrice = $this->$method($newPrice, $policy);
        }
        return $newPrice->format();
        
    }

    public function percent(PriceClass $price, DynamicPricing $policy)
    {
        if ($policy->amount_operation == 'decrease') {
            return $price->decreasePercent($policy->amount);
            
        }
        if ($policy->amount_operation == 'increase') {
            return $price->increasePercent($policy->amount);
        }
        return $price;
    }
    
    public function fixed(PriceClass $price, DynamicPricing $policy)
    {
        if ($policy->amount_operation == 'decrease') {
            return $price->subtract(Price::create($policy->amount));
            
        }
        if ($policy->amount_operation == 'increase') {
            return $price->add(Price::create($policy->amount));
        }
        return $price;
    }

    public function scopeSearchForAvailability($query, $statamic_id, $price, $date_start, $date_end, $duration)
    {
       $itemsForId = DB::table('resrv_dynamic_pricing_assignments')
                ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Availability')
                ->where('dynamic_pricing_assignment_id', $statamic_id)
                ->orderBy('order')
                ->get();

        if ($itemsForId->count() == 0) {
            return false;
        }

        $toApply = $this->checkAllParameters($itemsForId, $price, $date_start, $date_end, $duration);

        if (count($toApply) == 0) {
            return false;
        }

        $this->toApply = $toApply;

        return $this;
       
    }
    
    public function scopeSearchForExtra($query, $extra_id, $price, $date_start, $date_end, $duration)
    {
        $itemsForId = DB::table('resrv_dynamic_pricing_assignments')
                ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Extra')
                ->where('dynamic_pricing_assignment_id', $extra_id)
                ->orderBy('order')
                ->get();

        if ($itemsForId->count() == 0) {
            return false;
        }
        $toApply = $this->checkAllParameters($itemsForId, $price, $date_start, $date_end, $duration);

        if (count($toApply) == 0) {
            return false;
        }

        $this->toApply = $toApply;

        return $this;
       
    }

    protected function checkAllParameters($items, $price, $date_start, $date_end, $duration)
    {
        $dynamicPricingThatApplies = [];
        foreach ($items as $item) {
            $pricing = $this->find($item->dynamic_pricing_id);
            if ($pricing->hasCondition()) {
                if (! $pricing->checkCondition($price, $duration)) {
                    continue;
                }
            }
            if ($pricing->hasDates()) {
                if (! $pricing->datesInRange($date_start, $date_end)) {
                    continue;
                }
            }
            $dynamicPricingThatApplies[] = $pricing;
        }
        return $dynamicPricingThatApplies;
    }

    protected function hasCondition()
    {
        return $this->condition_type;
    }

    protected function checkCondition($price = null, $duration = null)
    {   
        if ($this->condition_type == 'reservation_duration') {
            if ($this->compare($duration, $this->condition_comparison, $this->condition_value)) {
                return true;
            }
        }
        if ($this->condition_type == 'reservation_price') {
            if ($this->compare($price, $this->condition_comparison, $this->condition_value)) {
                return true;
            }
        }
        return false;
        
    }

    protected function hasDates()
    {
        if ($this->date_start && $this->date_end) {
            return true;
        }
        return false;
    }

    protected function datesInRange($date_start, $date_end)
    {
        $date_start = new Carbon($date_start);
        $date_end = new Carbon($date_end);

        if ($this->date_include == 'all') {
            if ($this->date_start->lessThanOrEqualTo($date_start) && $this->date_end->greaterThanOrEqualTo($date_end)) {
                return true;
            }
        } 
        
        if ($this->date_include == 'start') {
            if ($this->date_start->lessThanOrEqualTo($date_start) && $this->date_end->greaterThanOrEqualTo($date_start)) {
                return true;
            }
        }
        
        if ($this->date_include == 'most') {
            $duration = $date_start->startOfDay()->diffInDays($date_end->startOfDay());
            $reservationPeriod = CarbonPeriod::create($date_start, $date_end, CarbonPeriod::EXCLUDE_END_DATE);
            $dynamicPricingPeriod = CarbonPeriod::create($this->date_start, $this->date_end);
            $daysIncluded = 0;
            foreach ($reservationPeriod as $date) {
                if ($dynamicPricingPeriod->contains($date)) {
                    $daysIncluded++;
                }
            }
            if (($duration / 2) < $daysIncluded) {
                return true;
            }
        }

        return false;
    }
    

}

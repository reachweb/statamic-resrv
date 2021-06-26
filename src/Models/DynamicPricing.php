<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Database\Factories\DynamicPricingFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Facades\Entry;
use Carbon\CarbonPeriod;

class DynamicPricing extends Model
{
    use HasFactory;

    protected $table = 'resrv_dynamic_pricing';

    protected $fillable = ['title', 'amount_type', 'amount', 'date_start', 'date_end', 'condition_type', 'condition_comparison', 'condition_value'];

    protected $casts = [
        'amount' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return DynamicPricingFactory::new();
    }

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

    public function createEntries($data)
    {
        $dynamicPricing = $this->create($data);
        $dynamicPricing->entries()->sync($data['entries']);
        return $dynamicPricing;        
    }
    
    public function createExtras($data)
    {
        $dynamicPricing = $this->create($data);
        $dynamicPricing->extras()->sync($data['extras']);
        return $dynamicPricing;        
    }

}

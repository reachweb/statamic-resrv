<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Database\Factories\FixedPricingFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Statamic\Facades\Entry;
use Carbon\CarbonPeriod;

class FixedPricing extends Model
{
    use HasFactory;

    protected $table = 'resrv_fixed_pricing';

    protected $fillable = ['statamic_id', 'days', 'price'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return FixedPricingFactory::new();
    }

    public function scopeEntry($query, $entry)
    {
        return $query->where('statamic_id', $entry);
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }    

    /**
     * Gets the fixed pricing for this date 
     */
    protected function getPriceForDays($statamic_id) {

        

    }

}

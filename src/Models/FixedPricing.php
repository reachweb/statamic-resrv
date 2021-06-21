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

    public function existsExactly($statamic_id, $days) 
    {
        return $this->where('statamic_id', $statamic_id)->where('days', $days)->exists(); 
    }

    public function existsExtra($statamic_id, $days) {
        if ($this->where('statamic_id', $statamic_id)->where('days', 0)->exists() && ($this->where('statamic_id', $statamic_id)->max('days') > 0)) {
            return true;
        }
        return false;
    }

    public function scopeGetFixedPricing($query, $statamic_id, $days) 
    {        
        if ($this->existsExactly($statamic_id, $days)) {
            return $this->where('statamic_id', $statamic_id)->where('days', $days)->first()->price;
        }
        if ($this->existsExtra($statamic_id, $days)) {
            return $this->calculateExtraDaysPricing($statamic_id, $days);
        }
        return false;
    }

    protected function calculateExtraDaysPricing($statamic_id, $days) {
        $extraDaysPrice = $this->where('statamic_id', $statamic_id)->where('days', 0)->first()->price;
        $maxDaysSet = $this->where('statamic_id', $statamic_id)->max('days');
        $maxDaysPrice = $this->where('statamic_id', $statamic_id)->where('days', $maxDaysSet)->first()->price;
        $daysLeft = $days - $maxDaysSet;
        $daysLeftPrice = $extraDaysPrice->multiply($daysLeft);
        return $extraDaysPrice->add($maxDaysPrice);
    }

}

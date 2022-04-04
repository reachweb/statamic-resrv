<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\FixedPricingFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

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

    public function existsExtra($statamic_id, $days)
    {
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
            $price = $this->calculateExtraDaysPricing($statamic_id, $days);
            if ($price) {
                return $price;
            }
        }

        return false;
    }

    protected function calculateExtraDaysPricing($statamic_id, $days)
    {
        // The max days we have set up
        $maxDaysSet = $this->where('statamic_id', $statamic_id)->max('days');
        // If the days we are requesting for are less than the max days set, return false to fallback to calendar pricing
        if ($days < $maxDaysSet) {
            return false;
        }
        // The fixed price of the max days resrvation
        $maxDaysPrice = $this->where('statamic_id', $statamic_id)->where('days', $maxDaysSet)->first()->price;
        // How much each extra day is charged
        $extraDaysPrice = $this->where('statamic_id', $statamic_id)->where('days', 0)->first()->price;
        // Days left to calculate
        $daysLeft = $days - $maxDaysSet;
        $daysLeftPrice = $extraDaysPrice->multiply($daysLeft);

        return $extraDaysPrice->add($maxDaysPrice);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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

    public function existsExactly($items, $days)
    {
        return $items->where('days', $days)->count() > 0;
    }

    public function existsExtra($items, $days)
    {
        if ($items->where('days', 0)->count() > 0 && ($items->max('days') > 0)) {
            return true;
        }

        return false;
    }

    public function scopeGetFixedPricing($query, $statamic_id, $days)
    {
        $data = Cache::remember('fixed_pricing_table', 5, function () {
            return DB::table('resrv_fixed_pricing')->get();
        });
        $items = $data->where('statamic_id', $statamic_id);

        if ($this->existsExactly($items, $days)) {
            return Price::create($items->where('days', $days)->first()->price);
        }
        if ($this->existsExtra($items, $days)) {
            $price = $this->calculateExtraDaysPricing($items, $days);
            if ($price) {
                return $price;
            }
        }

        return false;
    }

    protected function calculateExtraDaysPricing($items, $days)
    {
        // The max days we have set up
        $maxDaysSet = $items->max('days');
        // If the days we are requesting for are less than the max days set, return false to fallback to calendar pricing
        if ($days < $maxDaysSet) {
            return false;
        }
        // The fixed price of the max days resrvation
        $maxDaysPrice = Price::create($items->where('days', $maxDaysSet)->first()->price);
        // How much each extra day is charged
        $extraDaysPrice = Price::create($items->where('days', 0)->first()->price);
        // Days left to calculate
        $daysLeft = $days - $maxDaysSet;
        $daysLeftPrice = $extraDaysPrice->multiply($daysLeft);

        return $extraDaysPrice->add($maxDaysPrice);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\OptionValueFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesOrdering;

class OptionValue extends Model
{
    use HandlesAvailabilityDates, HandlesOrdering, HasFactory, SoftDeletes;

    protected $table = 'resrv_options_values';

    protected $fillable = ['name', 'option_id', 'price', 'price_type', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return OptionValueFactory::new();
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function option()
    {
        return $this->belongsTo(Option::class, 'option_id');
    }

    public function priceForDates($data)
    {
        return $this->calculatePrice($data)->format();
    }

    public function calculatePrice($data)
    {
        if ($this->price_type == 'free') {
            return $this->price;
        }
        $this->initiateAvailabilityUnsafe($data);
        // The rate may divide the booked quantity (units_per_addon) — see HandlesAvailabilityDates::quantityForAddons().
        $quantity = config('resrv-config.ignore_quantity_for_prices', false) ? 1 : $this->quantityForAddons();
        $applyQuantity = $quantity > 1;

        if ($this->price_type == 'fixed') {
            return $applyQuantity ? $this->price->multiply($quantity) : $this->price;
        }
        if ($this->price_type == 'perday') {
            return $applyQuantity ? $this->price->multiply($this->duration)->multiply($quantity) : $this->price->multiply($this->duration);
        }

        // Unknown price type (e.g. legacy data): fall back to the base price instead of
        // returning null, which would fatal at the ->format() call in priceForDates().
        return $this->price;
    }

    protected function orderingQuery()
    {
        return $this->newQuery()->where('option_id', $this->option_id);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Database\Factories\OptionValueFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class OptionValue extends Model
{
    use HasFactory, HandlesOrdering, HandlesAvailabilityDates;

    protected $table = 'resrv_options_values';

    protected $fillable = ['name', 'price', 'price_type', 'description', 'order', 'published'];

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
        return $this->hasOne(Option::class);
    }

    public function priceForDates($dates)
    {
        $this->initiateAvailability($dates);
        return $this->price->format();
    }

    public function calculatePrice($dates, $quantity) 
    {
        $this->initiateAvailability($dates);
        if ($this->price_type == 'perday') {            
            return $this->price->multiply($quantity)->multiply($this->duration);
        }
        if ($this->price_type == 'fixed') {
            return $this->price->multiply($quantity);
        }
    }  
    

}

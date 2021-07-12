<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\ExtraFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class Extra extends Model
{
    use HasFactory, HandlesOrdering, HandlesAvailabilityDates;

    protected $table = 'resrv_extras';

    protected $fillable = ['name', 'slug', 'price', 'price_type', 'allow_multiple', 'maximum', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'allow_multiple' => 'boolean',
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return ExtraFactory::new();
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function calculatePrice($dates, $quantity) 
    {
        $this->initiateAvailability($dates);
        $dynamicPricing = $this->getDynamicPricing($this->id, $this->price->format());
        if ($dynamicPricing) {
            $this->price = $dynamicPricing->apply($this->price->format());
        }
        if ($this->price_type == 'perday') {            
            return $this->price->multiply($quantity)->multiply($this->duration);
        }
        if ($this->price_type == 'fixed') {
            return $this->price->multiply($quantity);
        }
    }

    public function scopeEntry($query, $entry)
    {
        return DB::table('resrv_extras')
            ->join('resrv_statamicentry_extra', function ($join) use ($entry) {
                $join->on('resrv_extras.id', '=', 'resrv_statamicentry_extra.extra_id')
                    ->where('resrv_statamicentry_extra.statamicentry_id', '=', $entry);
            })
            ->select('resrv_extras.*');
    }
    
    protected function getDynamicPricing($id, $price)
    {
        return DynamicPricing::searchForExtra($id, $price, $this->date_start, $this->date_end, $this->duration);
        
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Database\Factories\OptionValueFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class OptionValue extends Model
{
    use HasFactory, HandlesAvailabilityDates, SoftDeletes;

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
        return $this->hasOne(Option::class);
    }

    public function priceForDates($data)
    {
        // This is a placeholder for now, in order to add dynamic pricing for options
        return $this->price->format();
    }

    public function calculatePrice($data) 
    {
        if ($this->price_type == 'free' || $this->price_type == 'fixed') { 
            return $this->price;
        }
        $this->initiateAvailability($data);
        if ($this->price_type == 'perday') {            
            return $this->price->multiply($this->duration)->multiply($this->quantity);
        }
    }  

    public function changeOrder($order)
    {
        if ($this->order == $order) {
            return;
        }

        $items = $this->where('option_id', $this->option_id)->orderBy('order')->get()->keyBy('id');
        $movingItem = $items->pull($this->id);
        $count = ($order == 1 ? 2 : 1);

        foreach ($items as $item) {
            if ($count == $order) {
                $count++;
            }
            $item->order = $count;
            $item->saveOrFail();
            $count++;
        }
        $movingItem->order = $order;
        $movingItem->saveOrFail();
    }
    

}

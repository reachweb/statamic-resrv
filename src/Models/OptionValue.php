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

class OptionValue extends Model
{
    use HandlesAvailabilityDates, HasFactory, SoftDeletes;

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
        $this->initiateAvailabilityUnsafe($data instanceof Reservation ? $data->toArray() : $data);

        return $this->calculatePrice($data)->format();
    }

    public function calculatePrice($data)
    {
        if ($this->price_type == 'free') {
            return $this->price;
        }
        $this->initiateAvailabilityUnsafe($data instanceof Reservation ? $data->toArray() : $data);
        $applyQuantity = $this->quantity > 1 && ! config('resrv-config.ignore_quantity_for_prices', false);

        if ($this->price_type == 'fixed') {
            return $applyQuantity ? $this->price->multiply($this->quantity) : $this->price;
        }
        if ($this->price_type == 'perday') {
            return $applyQuantity ? $this->price->multiply($this->duration)->multiply($this->quantity) : $this->price->multiply($this->duration);
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

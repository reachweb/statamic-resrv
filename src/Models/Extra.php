<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\ExtraFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Traits\HandlesOrdering;

class Extra extends Model
{
    use HasFactory, HandlesOrdering, HandlesAvailabilityDates, HandlesMultisiteIds, SoftDeletes;

    protected $table = 'resrv_extras';

    protected $fillable = ['name', 'slug', 'price', 'price_type', 'allow_multiple', 'maximum', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'allow_multiple' => 'boolean',
        'price' => PriceClass::class,
    ];

    protected $with = ['conditions'];

    protected static function newFactory()
    {
        return ExtraFactory::new();
    }

    public function conditions()
    {
        return $this->hasMany(ExtraCondition::class);
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function priceForDates($data)
    {
        $this->initiateAvailability($data);
        $dynamicPricing = $this->getDynamicPricing($this->id, $this->price);
        if ($dynamicPricing) {
            $this->price = $dynamicPricing->apply($this->price)->format();
        }
        if ($this->price_type == 'relative') {
            return $this->price->multiply($this->getRelativePrice($data))->format();
        }

        return $this->price->format();
    }

    public function priceForReservation($reservation)
    {
        $data = [];
        $data['date_start'] = $reservation->date_start;
        $data['date_end'] = $reservation->date_end;
        $data['quantity'] = $reservation->quantity;
        $data['item_id'] = $reservation->item_id ?? $reservation->parent->item_id;
        if (isset($reservation->property)) {
            $data['advanced'] = $reservation->property;
        }

        return $this->priceForDates($data);
    }

    public function calculatePrice($data, $quantity)
    {
        $this->initiateAvailability($data);
        $dynamicPricing = $this->getDynamicPricing($this->id, $this->price);
        if ($dynamicPricing) {
            $this->price = $dynamicPricing->apply($this->price)->format();
        }
        if ($this->price_type == 'perday') {
            return $this->price->multiply($quantity)->multiply($this->duration)->multiply($this->quantity);
        }
        if ($this->price_type == 'fixed') {
            return $this->price->multiply($quantity)->multiply($this->quantity);
        }
        if ($this->price_type == 'relative') {
            return $this->price->multiply($this->getRelativePrice($data))->multiply($quantity)->multiply($this->quantity);
        }
    }

    public function scopeEntry($query, $entry)
    {
        $entry = $this->getDefaultSiteEntry($entry);

        return DB::table('resrv_extras')
            ->join('resrv_statamicentry_extra', function ($join) use ($entry) {
                $join->on('resrv_extras.id', '=', 'resrv_statamicentry_extra.extra_id')
                    ->where('resrv_statamicentry_extra.statamicentry_id', '=', $entry->id());
            })
            ->select('resrv_extras.*');
    }

    public function scopeGetPriceForDates($query, $data)
    {
        $extras = $this->scopeEntry($query, $data['item_id'])
            ->where('published', true)
            ->orderBy('order')
            ->get(['id', 'name', 'slug', 'price', 'price_type', 'allow_multiple', 'maximum', 'description', 'order']);

        $extras->transform(function ($extra) use ($data) {
            $extra->original_price = $extra->price;
            $extra->price = $this->find($extra->id)->priceForDates($data);

            return $extra;
        });

        return $extras;
    }

    protected function getRelativePrice($data)
    {
        return (new Availability())->getPriceForItem($data, $data['item_id'])->format();
    }

    protected function getDynamicPricing($id, $price)
    {
        return DynamicPricing::searchForExtra($id, $price, $this->date_start, $this->date_end, $this->duration);
    }
}

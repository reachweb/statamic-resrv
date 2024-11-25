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

class Extra extends Model
{
    use HandlesAvailabilityDates, HandlesMultisiteIds, HasFactory, SoftDeletes;

    protected $table = 'resrv_extras';

    protected $fillable = [
        'name',
        'slug',
        'category_id',
        'price',
        'price_type',
        'allow_multiple',
        'custom',
        'override_label',
        'maximum',
        'description',
        'order',
        'published',
    ];

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
        return $this->hasOne(ExtraCondition::class);
    }

    public function category()
    {
        return $this->hasOne(ExtraCategory::class);
    }

    public function entries()
    {
        return $this->belongsToMany(Entry::class, 'resrv_entry_extra');
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function changeOrder($order)
    {
        if ($this->order === $order) {
            return;
        }

        $items = $this->where('category_id', $this->category_id)->orderBy('order')->get()->keyBy('id');

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

    public function priceForDates($data)
    {
        $this->initiateAvailabilityUnsafe($data);
        $this->applyDynamicPricing();

        $basePrice = $this->calculatePriceByType($data);

        return $this->applyQuantityIfNeeded($basePrice)->format();
    }

    private function applyDynamicPricing()
    {
        $dynamicPricing = $this->getDynamicPricing($this->id, $this->price);
        if ($dynamicPricing) {
            $this->price = $dynamicPricing->apply($this->price)->format();
        }
    }

    private function calculatePriceByType($data)
    {
        switch ($this->price_type) {
            case 'relative':
                return $this->calculateRelativePrice($data);
            case 'perday':
                return $this->calculatePerDayPrice();
            case 'custom':
                return $this->calculateCustomPrice($data);
            default:
                return $this->calculateDefaultPrice();
        }
    }

    private function calculateRelativePrice($data)
    {
        return $this->price->multiply($this->getRelativePrice($data));
    }

    private function calculatePerDayPrice()
    {
        return $this->price->multiply($this->duration);
    }

    private function calculateCustomPrice($data)
    {
        return $this->price->multiply($this->getCustomPrice($data));
    }

    private function calculateDefaultPrice()
    {
        return $this->price;
    }

    private function applyQuantityIfNeeded($price)
    {
        if ($this->quantity > 1 && ! config('resrv-config.ignore_quantity_for_prices', false)) {
            $price = $price->multiply($this->quantity);
        }

        return $price;
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

    // TODO: merge these two methods ?
    // More info: this one calculates the price for the extra including the quantity the user has selected
    // and is used to validate the price for the reservation. Probably should merge with getPriceForDates.
    public function calculatePrice($data, $quantity)
    {
        $this->initiateAvailabilityUnsafe($data);
        $dynamicPricing = $this->getDynamicPricing($this->id, $this->price);
        if ($dynamicPricing) {
            $this->price = $dynamicPricing->apply($this->price)->format();
        }
        if ($this->price_type == 'perday') {
            $price = $this->price->multiply($quantity)->multiply($this->duration);
        }
        if ($this->price_type == 'fixed') {
            $price = $this->price->multiply($quantity);
        }
        if ($this->price_type == 'relative') {
            $price = $this->price->multiply($this->getRelativePrice($data))->multiply($quantity);
        }
        if ($this->price_type == 'custom') {
            $price = $this->price->multiply($this->getCustomPrice($data));
        }

        return $this->applyQuantityIfNeeded($price);
    }

    public function priceFromPivot()
    {
        return Price::create($this->pivot->price)->multiply($this->pivot->quantity)->format();
    }

    public function scopeEntriesWithConditions($query, $entry)
    {
        $statamicEntry = $this->getDefaultSiteEntry($entry);
        $entry = Entry::itemId($statamicEntry->id())->first();

        return DB::table('resrv_extras')
            ->join('resrv_entry_extra', function ($join) use ($entry) {
                $join->on('resrv_extras.id', '=', 'resrv_entry_extra.extra_id')
                    ->where('resrv_entry_extra.entry_id', '=', $entry->id);
            })
            ->join('resrv_extra_conditions', function ($join) {
                $join->on('resrv_extras.id', '=', 'resrv_extra_conditions.extra_id');
            })
            ->select('resrv_extras.*', 'resrv_extra_conditions.*');
    }

    public function scopeGetPriceForDates($query, $data)
    {
        $entry = Entry::itemId($data['item_id'])->first();

        $extras = $entry->extras()
            ->where('published', true)
            ->orderBy('order')
            ->get(['resrv_extras.id', 'name', 'slug', 'price', 'price_type', 'allow_multiple', 'custom', 'maximum', 'description', 'order']);

        $extras->transform(function ($extra) use ($data) {
            $extra->original_price = $extra->price->format();
            $extra->price = $extra->priceForDates($data);

            return $extra;
        });

        return $extras;
    }

    protected function getRelativePrice($data)
    {
        if ($data instanceof Reservation) {
            $data = $this->initiateAvailabilityFromReservation($data);
        }

        return (new Availability)->getPricing($data, $data['item_id'], true);
    }

    protected function getCustomPrice($reservation)
    {
        if (! $reservation instanceof Reservation) {
            if (array_key_exists('customer', $reservation)) {
                $customer = $reservation['customer'];
            } else {
                // This part handles the case when the extra is loaded before checkout
                if (session()->has('resrv-search') &&
                    isset(session('resrv-search')->customer) &&
                    array_key_exists($this->custom, session('resrv-search')->customer)
                ) {
                    return session('resrv-search')->customer[$this->custom];
                } else {
                    // Fail gracefull if not found
                    return 1;
                }
            }
        } else {
            if (! $reservation->customer || ! $reservation->customer->has($this->custom)) {
                return 1;
            }
            $customer = $reservation->customer;
        }

        if (! $customer->has($this->custom)) {
            throw new \Exception('The custom price data is missing for extra'.$this->name);
        }

        $value = $customer->get($this->custom);

        if (! is_numeric($value)) {
            throw new \Exception('The custom price data is not a number for extra'.$this->name);
        }

        return $value;
    }

    protected function initiateAvailabilityFromReservation($data)
    {
        $reservationData = [];
        $reservationData['date_start'] = $data->date_start;
        $reservationData['date_end'] = $data->date_end;
        $reservationData['quantity'] = $data->quantity;
        $reservationData['item_id'] = $data->item_id ?? $data->parent->item_id;
        if (isset($data->property)) {
            $reservationData['advanced'] = $data->property;
        }

        return $reservationData;
    }

    protected function getDynamicPricing($id, $price)
    {
        return DynamicPricing::searchForExtra($id, $price, $this->date_start, $this->date_end, $this->duration);
    }
}

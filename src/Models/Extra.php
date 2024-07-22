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
    use HandlesAvailabilityDates, HandlesMultisiteIds, HandlesOrdering, HasFactory, SoftDeletes;

    protected $table = 'resrv_extras';

    protected $fillable = [
        'name',
        'slug',
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
    public function calculatePrice($data, $quantity)
    {
        $this->initiateAvailabilityUnsafe($data);
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
        if ($this->price_type == 'custom') {
            return $this->price->multiply($this->getCustomPrice($data))->multiply($this->quantity);
        }
    }

    public function priceFromPivot()
    {
        return Price::create($this->pivot->price)->multiply($this->pivot->quantity)->format();
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

    public function scopeEntries($query)
    {
        return DB::table('resrv_statamicentry_extra')
            ->where('extra_id', $this->id);
    }

    public function scopeEntriesWithConditions($query, $entry)
    {
        $entry = $this->getDefaultSiteEntry($entry);

        return DB::table('resrv_extras')
            ->join('resrv_statamicentry_extra', function ($join) use ($entry) {
                $join->on('resrv_extras.id', '=', 'resrv_statamicentry_extra.extra_id')
                    ->where('resrv_statamicentry_extra.statamicentry_id', '=', $entry->id());
            })
            ->join('resrv_extra_conditions', function ($join) {
                $join->on('resrv_extras.id', '=', 'resrv_extra_conditions.extra_id');
            })
            ->select('resrv_extras.*', 'resrv_extra_conditions.*');
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
        if ($data instanceof Reservation) {
            $data = $this->initiateAvailabilityFromReservation($data);
        }

        return (new Availability())->getPriceForItem($data, $data['item_id'])->format();
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

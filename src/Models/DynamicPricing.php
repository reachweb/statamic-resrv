<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\DynamicPricingFactory;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesComparisons;
use Reach\StatamicResrv\Traits\HandlesOrdering;

class DynamicPricing extends Model
{
    use HasFactory, HandlesComparisons, HandlesOrdering;

    protected $table = 'resrv_dynamic_pricing';

    protected $fillable = [
        'title',
        'amount_type',
        'amount_operation',
        'amount',
        'date_start',
        'date_end',
        'date_include',
        'condition_type',
        'condition_comparison',
        'condition_value',
        'order',
        'coupon',
        'expire_at',
        'overrides_all',
    ];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        'expire_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return DynamicPricingFactory::new();
    }

    public $toApply;

    public function getAmount($value)
    {
        if ($this->amount_type == 'percent') {
            return $value;
        }

        return Price::create($value);
    }

    public function extras()
    {
        return $this->morphedByMany(
            Extra::class,
            'dynamic_pricing_assignment',
            'resrv_dynamic_pricing_assignments',
            'dynamic_pricing_id',
            'dynamic_pricing_assignment_id'
        );
    }

    public function entries()
    {
        return $this->morphedByMany(
            Availability::class,
            'dynamic_pricing_assignment',
            'resrv_dynamic_pricing_assignments',
            'dynamic_pricing_id',
            'dynamic_pricing_assignment_id'
        );
    }

    public function getEntriesAttribute($value)
    {
        $entries = DB::table('resrv_dynamic_pricing_assignments')
                ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Availability')
                ->where('dynamic_pricing_id', $this->id)
                ->get();

        return $entries->map(function ($item) {
            return $item->dynamic_pricing_assignment_id;
        });
    }

    public function getExtrasAttribute($value)
    {
        $extras = $this->extras()->get();

        return $extras->map(function ($item) {
            return $item->id;
        });
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function apply($price)
    {
        foreach ($this->toApply as $policy) {
            $method = $policy->amount_type;
            $price = $this->$method($price, $policy);
        }

        return $price;
    }

    public function percent(PriceClass $price, $policy)
    {
        if ($policy->amount_operation == 'decrease') {
            return $price->decreasePercent($policy->amount);
        }
        if ($policy->amount_operation == 'increase') {
            return $price->increasePercent($policy->amount);
        }

        return $price;
    }

    public function fixed(PriceClass $price, $policy)
    {
        if ($policy->amount_operation == 'decrease') {
            return $price->subtract(Price::create($policy->amount));
        }
        if ($policy->amount_operation == 'increase') {
            return $price->add(Price::create($policy->amount));
        }

        return $price;
    }

    public function scopeSearchForAvailability($query, $statamic_id, $price, $date_start, $date_end, $duration)
    {
        $data = Cache::remember('dynamic_pricing_assignments_table', 60, function () {
            return DB::table('resrv_dynamic_pricing_assignments')->get();
        });

        $itemsForId = $data->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Availability')
                ->where('dynamic_pricing_assignment_id', $statamic_id);

        if ($itemsForId->count() == 0) {
            return false;
        }

        $toApply = $this->checkAllParameters($itemsForId, $price, $date_start, $date_end, $duration);

        if (count($toApply) == 0) {
            return false;
        }

        $this->toApply = $toApply;

        return $this;
    }

    public function scopeSearchForExtra($query, $extra_id, $price, $date_start, $date_end, $duration)
    {
        $data = Cache::remember('dynamic_pricing_assignments_table', 60, function () {
            return DB::table('resrv_dynamic_pricing_assignments')->get();
        });

        $itemsForId = $data->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Extra')
                ->where('dynamic_pricing_assignment_id', $extra_id);

        if ($itemsForId->count() == 0) {
            return false;
        }
        $toApply = $this->checkAllParameters($itemsForId, $price, $date_start, $date_end, $duration);

        if (count($toApply) == 0) {
            return false;
        }

        $this->toApply = $toApply;

        return $this;
    }

    public function scopeSearchForCoupon($query, $coupon)
    {
        $items = $query->where('coupon', $coupon)->get();
        if ($items->count() > 0) {
            return $items;
        } else {
            throw new CouponNotFoundException(__('This coupon does not exist.'));
        }
    }

    protected function checkAllParameters($items, $price, $date_start, $date_end, $duration)
    {
        $dynamicPricingThatApplies = collect();

        $data = Cache::remember('dynamic_pricing_table', 60, function () {
            return DB::table('resrv_dynamic_pricing')->get();
        }, 120);

        foreach ($items as $item) {
            $pricing = $data->firstWhere('id', $item->dynamic_pricing_id);
            if ($this->expired($pricing)) {
                continue;
            }
            if ($this->hasCoupon($pricing)) {
                if ($this->couponNotApplied($pricing)) {
                    continue;
                }
            }
            if ($this->hasCondition($pricing)) {
                if (! $this->checkCondition($pricing, $price, $duration, $date_start)) {
                    continue;
                }
            }
            if ($this->hasDates($pricing)) {
                if (! $this->datesInRange($pricing, $date_start, $date_end)) {
                    continue;
                }
            }
            $dynamicPricingThatApplies->push($pricing);
        }

        if ($override = $this->hasOverridingPolicy($dynamicPricingThatApplies->sortBy('order'))) {
            return $override;
        }

        return $dynamicPricingThatApplies->sortBy('order');
    }

    protected function hasCondition($pricing)
    {
        return $pricing->condition_type;
    }

    protected function hasCoupon($pricing)
    {
        if ($pricing->coupon) {
            return true;
        }

        return false;
    }

    protected function couponNotApplied($pricing)
    {
        return $pricing->coupon !== session('resrv_coupon');
    }

    protected function expired($pricing)
    {
        if (! $pricing->expire_at) {
            return false;
        }
        if (Carbon::parse($pricing->expire_at)->lessThan(now())) {
            return true;
        }
    }

    protected function checkCondition($pricing, PriceClass $price = null, $duration = null, $date_start = null)
    {
        if ($pricing->condition_type == 'reservation_duration') {
            if ($this->compare($duration, $pricing->condition_comparison, $pricing->condition_value)) {
                return true;
            }
        }
        if ($pricing->condition_type == 'reservation_price') {
            if ($this->compare($price->format(), $pricing->condition_comparison, $pricing->condition_value)) {
                return true;
            }
        }
        if ($pricing->condition_type == 'days_to_reservation') {
            if ($this->compare(Carbon::parse($date_start)->diffInDays(now()->setHour(0)), $pricing->condition_comparison, $pricing->condition_value)) {
                return true;
            }
        }

        return false;
    }

    protected function hasDates($pricing)
    {
        if ($pricing->date_start && $pricing->date_end) {
            return true;
        }

        return false;
    }

    protected function datesInRange($pricing, $date_start, $date_end)
    {
        $date_start = new Carbon($date_start);
        $date_end = new Carbon($date_end);

        if ($pricing->date_include == 'all') {
            if (Carbon::parse($pricing->date_start)->lessThanOrEqualTo($date_start) && Carbon::parse($pricing->date_end)->greaterThanOrEqualTo($date_end)) {
                return true;
            }
        }

        if ($pricing->date_include == 'start') {
            if (Carbon::parse($pricing->date_start)->lessThanOrEqualTo($date_start) && Carbon::parse($pricing->date_end)->greaterThanOrEqualTo($date_start)) {
                return true;
            }
        }

        if ($pricing->date_include == 'most') {
            $duration = $date_start->startOfDay()->diffInDays($date_end->startOfDay());
            $reservationPeriod = CarbonPeriod::create($date_start, $date_end, CarbonPeriod::EXCLUDE_END_DATE);
            $dynamicPricingPeriod = CarbonPeriod::create($pricing->date_start, $pricing->date_end);
            $daysIncluded = 0;
            foreach ($reservationPeriod as $date) {
                if ($dynamicPricingPeriod->contains($date)) {
                    $daysIncluded++;
                }
            }
            if (($duration / 2) < $daysIncluded) {
                return true;
            }
        }

        return false;
    }

    protected function hasOverridingPolicy($pricings): bool | Collection
    {
        if ($pricing = $pricings->firstWhere('overrides_all', true)) {
            return collect([$pricing]);
        }
        return false;
    }
}

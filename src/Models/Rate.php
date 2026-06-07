<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Database\Factories\RateFactory;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;

class Rate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'resrv_rates';

    private static array $entryCollectionCache = [];

    /**
     * The data type of the primary key ID.
     * Set to string for PostgreSQL compatibility with dynamic_pricing_assignments table.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $fillable = [
        'collection',
        'apply_to_all',
        'title',
        'slug',
        'description',
        'pricing_type',
        'base_rate_id',
        'modifier_type',
        'modifier_operation',
        'modifier_amount',
        'availability_type',
        'require_price_override',
        'max_available',
        'date_start',
        'date_end',
        'min_days_before',
        'max_days_before',
        'min_stay',
        'max_stay',
        'refundable',
        'cancellation_policy',
        'free_cancellation_period',
        'order',
        'published',
    ];

    protected $casts = [
        'apply_to_all' => 'boolean',
        'published' => 'boolean',
        'refundable' => 'boolean',
        'require_price_override' => 'boolean',
        'date_start' => 'date',
        'date_end' => 'date',
        'modifier_amount' => 'decimal:2',
        'base_rate_id' => 'string',
        'free_cancellation_period' => 'integer',
    ];

    protected static function newFactory(): RateFactory
    {
        return RateFactory::new();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrderScope);
    }

    public static function renameTrashedSlugs(string $collection, string $slug): void
    {
        static::onlyTrashed()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->get()
            ->each(fn (self $trashed) => $trashed->updateQuietly(['slug' => $trashed->slug.'-deleted-'.$trashed->id]));
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'resrv_rate_entries', 'rate_id', 'statamic_id', 'id', 'item_id')
            ->withTimestamps();
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class, 'rate_id');
    }

    public function baseRate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_rate_id');
    }

    public function dependentRates(): HasMany
    {
        return $this->hasMany(self::class, 'base_rate_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'rate_id');
    }

    public function childReservations(): HasMany
    {
        return $this->hasMany(ChildReservation::class, 'rate_id');
    }

    public function fixedPricing(): HasMany
    {
        return $this->hasMany(FixedPricing::class, 'rate_id');
    }

    public function ratePrices(): HasMany
    {
        return $this->hasMany(RatePrice::class, 'rate_id');
    }

    public function scopeForEntry(Builder $query, string $entryId): void
    {
        $collection = static::$entryCollectionCache[$entryId]
            ??= Entry::where('item_id', $entryId)->value('collection');

        if (! $collection) {
            $query->whereNull('id');

            return;
        }

        $query->where('collection', $collection)
            ->where(function (Builder $q) use ($entryId) {
                $q->where('apply_to_all', true)
                    ->orWhereHas('entries', function (Builder $q) use ($entryId) {
                        $q->where('resrv_entries.item_id', $entryId);
                    });
            });
    }

    public function scopeForCollection(Builder $query, string $collection): void
    {
        $query->where('collection', $collection);
    }

    public function changeOrder(int $order): void
    {
        if ((int) $this->order === $order) {
            return;
        }

        $items = static::where('collection', $this->collection)
            ->where('base_rate_id', $this->base_rate_id)
            ->orderBy('order')
            ->get()
            ->keyBy('id');

        $movingItem = $items->pull($this->id);
        $count = ($order === 1 ? 2 : 1);

        foreach ($items as $item) {
            if ($count === $order) {
                $count++;
            }
            $item->order = $count;
            $item->saveOrFail();
            $count++;
        }
        $movingItem->order = $order;
        $movingItem->saveOrFail();
    }

    public function isRelative(): bool
    {
        return $this->pricing_type === 'relative';
    }

    public function isShared(): bool
    {
        return $this->availability_type === 'shared';
    }

    public function hasIndependentSharedPricing(): bool
    {
        return $this->isShared() && ! $this->isRelative();
    }

    public function appliesToEntry(string $statamicId): bool
    {
        return $this->apply_to_all || $this->entries->contains('item_id', $statamicId);
    }

    public function isAvailableForDates(string $dateStart, string $dateEnd): bool
    {
        $start = Carbon::parse($dateStart);
        $end = Carbon::parse($dateEnd);

        if ($this->date_start && $start->lt($this->date_start)) {
            return false;
        }

        if ($this->date_end && $end->copy()->subDay()->gt($this->date_end)) {
            return false;
        }

        return true;
    }

    public function dateIsWithinWindow(string $date): bool
    {
        $d = Carbon::parse($date);

        if ($this->date_start && $d->lt($this->date_start)) {
            return false;
        }

        if ($this->date_end && $d->gt($this->date_end)) {
            return false;
        }

        return true;
    }

    public function meetsStayRestrictions(int $duration): bool
    {
        if ($this->min_stay && $duration < $this->min_stay) {
            return false;
        }

        if ($this->max_stay && $duration > $this->max_stay) {
            return false;
        }

        return true;
    }

    public function meetsBookingLeadTime(string $dateStart): bool
    {
        if (is_null($this->min_days_before) && is_null($this->max_days_before)) {
            return true;
        }

        $start = Carbon::parse($dateStart);
        $daysUntilStart = Carbon::today()->diffInDays($start, false);

        if (! is_null($this->min_days_before) && $daysUntilStart < $this->min_days_before) {
            return false;
        }

        if (! is_null($this->max_days_before) && $daysUntilStart > $this->max_days_before) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the cancellation policy that applies to this rate. A NULL column means the
     * rate inherits the global resrv-config.free_cancellation_period setting.
     *
     * @return array{policy: CancellationPolicy, period: ?int}
     */
    public function effectiveCancellationPolicy(): array
    {
        $policy = CancellationPolicy::tryFrom($this->cancellation_policy ?? '');

        if ($policy === CancellationPolicy::NonRefundable) {
            return ['policy' => $policy, 'period' => null];
        }

        if ($policy === CancellationPolicy::FreeCancellation) {
            return [
                'policy' => $policy,
                // A missing period (impossible via the CP, but defensively) inherits the global one —
                // an explicit 0 is meaningful (free cancellation until check-in) and is kept as-is.
                'period' => $this->free_cancellation_period ?? CancellationPolicy::globalDefault()['period'],
            ];
        }

        return CancellationPolicy::globalDefault();
    }

    public function isNonRefundable(): bool
    {
        return $this->effectiveCancellationPolicy()['policy'] === CancellationPolicy::NonRefundable;
    }

    /**
     * Resolve the effective cancellation policy for a rate id, falling back to the global
     * default when the id is empty or unknown. withTrashed so a rate soft-deleted mid-session
     * still resolves to the terms it carried.
     *
     * @return array{policy: CancellationPolicy, period: ?int}
     */
    public static function effectiveCancellationPolicyFor(?int $rateId): array
    {
        if (! $rateId) {
            return CancellationPolicy::globalDefault();
        }

        return static::withTrashed()->find($rateId)?->effectiveCancellationPolicy()
            ?? CancellationPolicy::globalDefault();
    }

    public function calculatePrice(PriceClass $basePrice): PriceClass
    {
        if (! $this->isRelative()) {
            return $basePrice;
        }

        $price = Price::create($basePrice->format());

        if ($this->modifier_type === 'percent') {
            $result = $this->modifier_operation === 'increase'
                ? $price->increasePercent($this->modifier_amount)
                : $price->decreasePercent($this->modifier_amount);
        } else {
            $modifier = Price::create($this->modifier_amount);
            $result = $this->modifier_operation === 'increase'
                ? $price->add($modifier)
                : $price->subtract($modifier);
        }

        return $this->floorAtZero($result);
    }

    /**
     * Apply the relative modifier to a total price (e.g. inherited fixed pricing).
     * For percentage modifiers, applies directly. For flat modifiers, scales by duration
     * since the flat amount represents a per-day adjustment.
     */
    public function calculateTotalPrice(PriceClass $totalPrice, int $duration): PriceClass
    {
        if (! $this->isRelative()) {
            return $totalPrice;
        }

        $price = Price::create($totalPrice->format());

        if ($this->modifier_type === 'percent') {
            $result = $this->modifier_operation === 'increase'
                ? $price->increasePercent($this->modifier_amount)
                : $price->decreasePercent($this->modifier_amount);
        } else {
            $modifier = Price::create($this->modifier_amount)->multiply($duration);
            $result = $this->modifier_operation === 'increase'
                ? $price->add($modifier)
                : $price->subtract($modifier);
        }

        return $this->floorAtZero($result);
    }

    /**
     * A relative "decrease" modifier larger than the base must never drive a price below zero.
     */
    protected function floorAtZero(PriceClass $price): PriceClass
    {
        return $price->lessThan(Price::create(0)) ? Price::create(0) : $price;
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    public static function findOrCreateDefaultForEntry(string $statamicId): ?self
    {
        $entry = Entry::where('item_id', $statamicId)->first();
        if (! $entry) {
            return null;
        }

        // withTrashed() to respect unique(collection, slug) constraint — Rate uses SoftDeletes
        $rate = static::withTrashed()
            ->where('collection', $entry->collection)
            ->where('slug', 'default')
            ->first();

        if ($rate && $rate->trashed()) {
            $rate->restore();
            Cache::forget('resrv_rates_exist');
            $rate->update([
                'published' => true,
                'apply_to_all' => true,
                'pricing_type' => 'independent',
                'availability_type' => 'independent',
                'base_rate_id' => null,
                'modifier_type' => null,
                'modifier_operation' => null,
                'modifier_amount' => null,
            ]);
        }

        if (! $rate) {
            $rate = static::create([
                'collection' => $entry->collection,
                'apply_to_all' => true,
                'title' => 'Default',
                'slug' => 'default',
                'published' => true,
            ]);
            Cache::forget('resrv_rates_exist');
        }

        // If existing rate is not apply_to_all, attach pivot so forEntry() finds it
        if (! $rate->apply_to_all) {
            $rate->entries()->syncWithoutDetaching([$statamicId]);
        }

        return $rate;
    }

    public static function resetEntryCollectionCache(): void
    {
        static::$entryCollectionCache = [];
    }
}

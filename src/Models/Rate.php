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
use Reach\StatamicResrv\Database\Factories\RateFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;

class Rate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'resrv_rates';

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
        'max_available',
        'date_start',
        'date_end',
        'min_days_before',
        'max_days_before',
        'min_stay',
        'max_stay',
        'refundable',
        'order',
        'published',
    ];

    protected $casts = [
        'apply_to_all' => 'boolean',
        'published' => 'boolean',
        'refundable' => 'boolean',
        'date_start' => 'date',
        'date_end' => 'date',
        'modifier_amount' => 'decimal:2',
    ];

    protected static function newFactory(): RateFactory
    {
        return RateFactory::new();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrderScope);
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

    public function fixedPricing(): HasMany
    {
        return $this->hasMany(FixedPricing::class, 'rate_id');
    }

    public function scopeForEntry(Builder $query, string $entryId): void
    {
        $collection = Entry::where('item_id', $entryId)->value('collection');

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

    public function isRelative(): bool
    {
        return $this->pricing_type === 'relative';
    }

    public function isShared(): bool
    {
        return $this->availability_type === 'shared';
    }

    public function isAvailableForDates(string $dateStart, string $dateEnd): bool
    {
        $start = Carbon::parse($dateStart);
        $end = Carbon::parse($dateEnd);

        if ($this->date_start && $start->lt($this->date_start)) {
            return false;
        }

        if ($this->date_end && $end->gt($this->date_end)) {
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
        if (! $this->min_days_before && ! $this->max_days_before) {
            return true;
        }

        $start = Carbon::parse($dateStart);
        $daysUntilStart = Carbon::today()->diffInDays($start, false);

        if ($this->min_days_before && $daysUntilStart < $this->min_days_before) {
            return false;
        }

        if ($this->max_days_before && $daysUntilStart > $this->max_days_before) {
            return false;
        }

        return true;
    }

    public function calculatePrice(PriceClass $basePrice): PriceClass
    {
        if (! $this->isRelative()) {
            return $basePrice;
        }

        $price = Price::create($basePrice->format());

        if ($this->modifier_type === 'percent') {
            return $this->modifier_operation === 'increase'
                ? $price->increasePercent($this->modifier_amount)
                : $price->decreasePercent($this->modifier_amount);
        }

        $modifier = Price::create($this->modifier_amount);

        return $this->modifier_operation === 'increase'
            ? $price->add($modifier)
            : $price->subtract($modifier);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }
}

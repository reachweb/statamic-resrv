<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'statamic_id',
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
        'min_stay',
        'max_stay',
        'refundable',
        'order',
        'published',
    ];

    protected $casts = [
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'statamic_id', 'item_id');
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
        if (! $this->min_days_before) {
            return true;
        }

        $start = Carbon::parse($dateStart);
        $daysUntilStart = Carbon::today()->diffInDays($start, false);

        return $daysUntilStart >= $this->min_days_before;
    }

    public function calculatePrice(PriceClass $basePrice): PriceClass
    {
        if (! $this->isRelative()) {
            return $basePrice;
        }

        $price = Price::create($basePrice->format());

        if ($this->modifier_type === 'percent') {
            if ($this->modifier_operation === 'increase') {
                return $price->increasePercent($this->modifier_amount);
            }

            return $price->decreasePercent($this->modifier_amount);
        }

        $modifier = Price::create($this->modifier_amount);

        if ($this->modifier_operation === 'increase') {
            return $price->add($modifier);
        }

        return $price->subtract($modifier);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }
}

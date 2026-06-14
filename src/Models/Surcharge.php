<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\SurchargeFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Scopes\OrderScope;

class Surcharge extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'resrv_surcharges';

    protected $fillable = [
        'name',
        'slug',
        'first_option_id',
        'second_option_id',
        'comparison',
        'price',
        'order',
        'published',
    ];

    protected $casts = [
        'published' => 'boolean',
        'price' => PriceClass::class,
    ];

    protected static function newFactory(): SurchargeFactory
    {
        return SurchargeFactory::new();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrderScope);
    }

    public function getPriceAttribute($value): PriceClass
    {
        return Price::create($value);
    }

    public function firstOption(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'first_option_id');
    }

    public function secondOption(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'second_option_id');
    }

    /**
     * The published surcharges that apply to a set of option selections. Accepts a map of
     * [optionId => selectedValueId] — works for both the frontend checkout selections and a stored
     * reservation's option pivots — and resolves the selected values' names once for the whole set.
     *
     * @param  array<int, int|string|null>  $optionSelections
     * @return Collection<int, self>
     */
    public static function matchingForSelections(array $optionSelections): Collection
    {
        $surcharges = static::where('published', true)->get();

        if ($surcharges->isEmpty()) {
            return $surcharges;
        }

        // Resolve the selected values' names once. The comparison is by NAME, not value id:
        // "Pickup location" and "Return location" are distinct Options with distinct value rows,
        // so the same place (e.g. "Airport") only matches when the admin names both values alike.
        $valueNames = OptionValue::withTrashed()
            ->whereIn('id', array_values(array_filter($optionSelections, fn ($v) => $v !== null)))
            ->pluck('name', 'id')
            ->all();

        return $surcharges
            ->filter(fn (self $surcharge) => $surcharge->appliesTo($optionSelections, $valueNames))
            ->values();
    }

    /**
     * The total of all published surcharges that apply to a set of option selections.
     *
     * @param  array<int, int|string|null>  $optionSelections
     */
    public static function totalForSelections(array $optionSelections): PriceClass
    {
        $total = Price::create(0);

        foreach (static::matchingForSelections($optionSelections) as $surcharge) {
            $total->add(Price::create($surcharge->price->format()));
        }

        return $total;
    }

    /**
     * Whether this surcharge fires for a set of option selections: both referenced options must be
     * selected, and their chosen values' names must relate as configured (differ, or match).
     *
     * @param  array<int, int|string|null>  $optionSelections
     * @param  array<int, string>  $valueNames  Pre-resolved [valueId => name].
     */
    public function appliesTo(array $optionSelections, array $valueNames): bool
    {
        $firstValueId = $optionSelections[$this->first_option_id] ?? null;
        $secondValueId = $optionSelections[$this->second_option_id] ?? null;

        // Both ends must be chosen to compare them; an incomplete pair never charges.
        if ($firstValueId === null || $secondValueId === null) {
            return false;
        }

        $firstName = $valueNames[$firstValueId] ?? null;
        $secondName = $valueNames[$secondValueId] ?? null;

        if ($firstName === null || $secondName === null) {
            return false;
        }

        $namesMatch = mb_strtolower(trim($firstName)) === mb_strtolower(trim($secondName));

        return $this->comparison === 'matches' ? $namesMatch : ! $namesMatch;
    }
}

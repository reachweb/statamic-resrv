<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\OptionFactory;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Scopes\OrderScope;

class Option extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'resrv_options';

    /**
     * Cache of the collection a Statamic entry belongs to, keyed by item id. Avoids a
     * resrv_entries lookup per scopeEntry() call within a request. Reset on app terminate.
     *
     * @var array<string, ?string>
     */
    private static array $entryCollectionCache = [];

    protected $fillable = ['name', 'slug', 'collection', 'apply_to_all', 'required', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'required' => 'boolean',
        'apply_to_all' => 'boolean',
    ];

    protected $with = ['values'];

    protected static function newFactory()
    {
        return OptionFactory::new();
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class);
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'resrv_option_entries', 'option_id', 'statamic_id', 'id', 'item_id')
            ->withTimestamps();
    }

    public function valuesPriceForDates($data)
    {
        foreach ($this->values as $value) {
            $value->original_price = $value->price->format();
            $value->price = $value->priceForDates($data);
        }

        return $this;
    }

    public function calculatePrice($data, $value)
    {
        // withTrashed() so historical reservations referencing a soft-deleted value still price
        // (mirrors the Extra path in Reservation::extraCharges). A value that is still unresolved
        // is invalid input (e.g. a tampered checkout payload) and must fail validation, not be
        // priced as free — otherwise a required/paid option could be synced at no charge.
        $value = $this->values()->withTrashed()->find($value);

        if (! $value) {
            throw new OptionsException(__('The selected option value is not valid.'));
        }

        return $value->calculatePrice($data);
    }

    /**
     * Resolve the options that apply to a Statamic entry: those in the entry's collection that
     * either apply to the whole collection or are explicitly attached via the pivot. Mirrors
     * Rate::forEntry(). A null collection (deleted/unknown entry) matches nothing.
     */
    public function scopeEntry(Builder $query, string $entry): void
    {
        $collection = static::$entryCollectionCache[$entry]
            ??= Entry::where('item_id', $entry)->value('collection');

        if (! $collection) {
            $query->whereNull('id');

            return;
        }

        $query->where('collection', $collection)
            ->where(function (Builder $q) use ($entry) {
                $q->where('apply_to_all', true)
                    ->orWhereHas('entries', function (Builder $q) use ($entry) {
                        $q->where('resrv_entries.item_id', $entry);
                    });
            });
    }

    public function scopeForCollection(Builder $query, string $collection): void
    {
        $query->where('collection', $collection);
    }

    public function appliesToEntry(string $statamicId): bool
    {
        return $this->apply_to_all || $this->entries->contains('item_id', $statamicId);
    }

    /**
     * Reorder within the option's own collection only, so reordering an option in one collection
     * never renumbers options in another (the global HandlesOrdering trait would). Mirrors Rate.
     */
    public function changeOrder($order): void
    {
        if ((int) $this->order === (int) $order) {
            return;
        }

        $items = static::where('collection', $this->collection)
            ->orderBy('order')
            ->get()
            ->keyBy('id');

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

    public static function resetEntryCollectionCache(): void
    {
        static::$entryCollectionCache = [];
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
        return $this->belongsTo(Option::class, 'option_id');
    }

    /**
     * Entries for which this value is explicitly DISABLED (sparse exception rows). Used by the CP
     * to toggle a value off per entry; absence of a row means the value is enabled for the entry.
     */
    public function disabledEntries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'resrv_option_value_entries', 'option_value_id', 'statamic_id', 'id', 'item_id')
            ->withTimestamps();
    }

    /**
     * Ids of the option values explicitly disabled for an entry (the sparse resrv_option_value_entries
     * exception rows). An empty list means every published value is selectable for that entry.
     *
     * @return array<int, int>
     */
    public static function disabledIdsForEntry(string $entryId): array
    {
        return DB::table('resrv_option_value_entries')
            ->where('statamic_id', $entryId)
            ->pluck('option_value_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function priceForDates($data)
    {
        return $this->calculatePrice($data)->format();
    }

    public function calculatePrice($data)
    {
        if ($this->price_type == 'free') {
            return $this->price;
        }
        $this->initiateAvailabilityUnsafe($data);
        $applyQuantity = $this->quantity > 1 && ! config('resrv-config.ignore_quantity_for_prices', false);

        if ($this->price_type == 'fixed') {
            return $applyQuantity ? $this->price->multiply($this->quantity) : $this->price;
        }
        if ($this->price_type == 'perday') {
            return $applyQuantity ? $this->price->multiply($this->duration)->multiply($this->quantity) : $this->price->multiply($this->duration);
        }

        // Unknown price type (e.g. legacy data): fall back to the base price instead of
        // returning null, which would fatal at the ->format() call in priceForDates().
        return $this->price;
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

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\OptionFactory;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesOrdering;

class Option extends Model
{
    use HandlesOrdering, HasFactory, SoftDeletes;

    protected $table = 'resrv_options';

    protected $fillable = ['name', 'slug', 'item_id', 'required', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'required' => 'boolean',
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

    public function values()
    {
        return $this->hasMany(OptionValue::class);
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

    public function scopeEntry($query, $entry)
    {
        return $query->where('item_id', $entry);
    }
}

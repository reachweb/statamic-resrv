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

    public function calculatePrice($data, $value)
    {
        // withTrashed() so historical reservations referencing a soft-deleted value still price
        // (mirrors the Extra path in Reservation::extraCharges). New selections are vetted first
        // by AddonQuote::price() (a published value of this option), so a value that is still
        // unresolved here is a broken pivot (e.g. a hard-deleted value) and must throw rather
        // than price as free — otherwise a required/paid option could be synced at no charge.
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

    /**
     * The options a customer can pick: published, with only their published values loaded.
     * AddonQuote lists these and validates a chosen value against the loaded values (values()
     * also returns unpublished ones).
     */
    public function scopeSelectable($query)
    {
        return $query->where('resrv_options.published', true)
            ->with(['values' => fn ($values) => $values->where('published', true)]);
    }

    protected function orderingQuery()
    {
        return $this->newQuery()->entry($this->item_id);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Database\Factories\OptionFactory;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;

class Option extends Model
{
    use HasFactory, HandlesOrdering;

    protected $table = 'resrv_options';

    protected $fillable = ['name', 'slug', 'item_id', 'required', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'required' => 'boolean',
    ];

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

    public function valuesPriceForDates($dates)
    {
        foreach ($this->values as $value) {
            $value->original_price = $value->price;
            $value->price = $value->priceForDates($dates);
        }
        return $this;
    }

    public function scopeEntry($query, $entry)
    {
        return $query->where('item_id', $entry);
    }
   
}

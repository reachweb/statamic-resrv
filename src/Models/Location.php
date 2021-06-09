<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\LocationFactory;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Facades\Price;


class Location extends Model
{
    use HasFactory, HandlesOrdering;

    protected $table = 'resrv_locations';

    protected $fillable = ['name', 'slug', 'extra_charge', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'extra_charge' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return LocationFactory::new();
    }

    public function getExtraChargeAttribute($value)
    {
        return Price::create($value);
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }    

}

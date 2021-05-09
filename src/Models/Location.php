<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\LocationFactory;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;

class Location extends Model
{
    use HasFactory, HandlesOrdering;

    protected $table = 'resrv_locations';

    protected $fillable = ['name', 'slug', 'extra_charge', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
    ];

    protected static function newFactory()
    {
        return LocationFactory::new();
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    

}

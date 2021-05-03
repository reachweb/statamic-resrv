<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\LocationFactory;
use Illuminate\Support\Facades\DB;

class Location extends Model
{
    use HasFactory;

    protected $table = 'resrv_locations';

    protected $fillable = ['name', 'slug', 'extra_charge', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
    ];

    protected static function newFactory()
    {
        return LocationFactory::new();
    }

}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;


class Availability extends Model
{
    use HasFactory;

    protected $fillable = ['statamic_id', 'date', 'price', 'available'];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }
}

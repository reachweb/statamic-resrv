<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Reach\StatamicResrv\Database\Factories\AdvancedAvailabilityFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Money\Price as PriceClass;


class AdvancedAvailability extends Availability
{
    use HasFactory, HandlesAvailabilityDates, HandlesMultisiteIds;

    protected $table = 'resrv_advanced_availabilities';

    protected $fillable = ['statamic_id', 'date', 'price', 'available', 'property'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return AdvancedAvailabilityFactory::new();
    }


}

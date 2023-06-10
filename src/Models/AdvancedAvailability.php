<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Reach\StatamicResrv\Database\Factories\AdvancedAvailabilityFactory;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Facades\Blueprint;

class AdvancedAvailability extends Availability
{
    use HasFactory, HandlesAvailabilityDates, HandlesMultisiteIds;

    protected $table = 'resrv_advanced_availabilities';

    protected $primaryKey = 'statamic_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['statamic_id', 'date', 'price', 'available', 'property'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return AdvancedAvailabilityFactory::new();
    }

    public function getPropertyLabel($handle, $collection, $slug)
    {
        $blueprint = Blueprint::find('collections.'.$collection.'.'.$handle);
        if (! $blueprint->hasField('resrv_availability')) {
            return false;
        }
        $properties = $blueprint->field('resrv_availability')->get('advanced_availability');
        if (array_key_exists($slug, $properties)) {
            return $properties[$slug];
        }

        return $slug;
    }
}

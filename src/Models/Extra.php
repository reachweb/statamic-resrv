<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Database\Factories\ExtraFactory;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesOrdering;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Facades\Price;

class Extra extends Model
{
    use HasFactory, HandlesOrdering, HandlesAvailabilityDates;

    protected $table = 'resrv_extras';

    protected $fillable = ['name', 'slug', 'price', 'price_type', 'allow_multiple', 'maximum', 'description', 'order', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'allow_multiple' => 'boolean',
    ];

    protected static function newFactory()
    {
        return ExtraFactory::new();
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }

    public function calculatePrice($dates, $quantity) {
        if ($this->price_type == 'perday') {
            $this->initiateAvailability($dates);
            $price = Price::create($this->price);
            return $price->multiply($quantity)->multiply($this->duration)->get();
        }
        if ($this->price_type == 'fixed') {
            $price = Price::create($this->price);
            return $price->multiply($quantity)->get();
        }
    }

    public function scopeEntry($query, $entry)
    {
        return DB::table('resrv_extras')
            ->join('resrv_statamicentry_extra', function ($join) use ($entry) {
                $join->on('resrv_extras.id', '=', 'resrv_statamicentry_extra.extra_id')
                    ->where('resrv_statamicentry_extra.statamicentry_id', '=', $entry);
            })
            ->select('resrv_extras.*');
    }
}

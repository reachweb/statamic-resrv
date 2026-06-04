<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class RatePrice extends Model
{
    protected $table = 'resrv_rate_prices';

    protected $fillable = [
        'rate_id',
        'statamic_id',
        'date',
        'price',
    ];

    protected $casts = [
        'price' => PriceClass::class,
        'date' => 'date',
    ];

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\AffiliateFactory;

class Affiliate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'resrv_affiliates';

    protected $fillable = [
        'name',
        'code',
        'email',
        'cookie_duration',
        'fee',
        'published',
        'allow_skipping_payment',
        'options',
    ];

    protected $casts = [
        'published' => 'boolean',
        'allow_skipping_payment' => 'boolean',
        'options' => 'array',
    ];

    protected static function newFactory()
    {
        return AffiliateFactory::new();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'send_reservation_email',
        'options',
    ];

    protected $casts = [
        'published' => 'boolean',
        'allow_skipping_payment' => 'boolean',
        'send_reservation_email' => 'boolean',
        'options' => 'array',
    ];

    protected static function newFactory()
    {
        return AffiliateFactory::new();
    }

    /**
     * The canonical read of the enable_affiliates setting. When false the whole affiliate
     * system is off: no cookies, no attribution (cookie or coupon), no skip-payment, no
     * affiliate emails, no CP nav or report section. Existing attributions are untouched
     * so history, reports and refund flows keep working.
     */
    public static function enabled(): bool
    {
        return (bool) config('resrv-config.enable_affiliates', true);
    }

    // Unpublished affiliates are disabled: they must not be cookied or attributed to reservations.
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'resrv_reservation_affiliate')->withPivot('fee', 'source', 'cancelled_at');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(DynamicPricing::class, 'resrv_affiliate_dynamic_pricing');
    }
}

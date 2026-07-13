<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Enums\AffiliateAttributionSource;
use Reach\StatamicResrv\Models\Affiliate;

trait HandlesAffiliates
{
    public function getAffiliateIfCookieExists(): ?Affiliate
    {
        return request()->cookie('resrv_afid') ? Affiliate::published()->where('code', request()->cookie('resrv_afid'))->first() : null;
    }

    public function affiliateCanSkipPayment(): bool
    {
        // Only a cookie attribution (the customer arrived through the affiliate link) can skip
        // payment. Coupon-sourced attributions earn commission but pay like everyone else.
        $affiliate = $this->reservation->affiliate()
            ->wherePivot('source', AffiliateAttributionSource::Cookie->value)
            ->first();

        return $affiliate?->allow_skipping_payment ?? false;
    }
}

<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Models\Affiliate;

trait HandlesAffiliates
{
    public function getAffiliateIfCookieExists(): ?Affiliate
    {
        return request()->cookie('resrv_afid') ? Affiliate::where('code', request()->cookie('resrv_afid'))->first() : null;
    }

    public function affiliateCanSkipPayment(): bool
    {
        if ($affiliate = $this->reservation->affiliate->first()) {
            return $affiliate->allow_skipping_payment ?? false;
        }

        return false;
    }
}

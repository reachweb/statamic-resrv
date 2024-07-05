<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Models\Affiliate;

trait HandlesAffiliates
{
    public function getAffiliateIfCookieExists(): ?Affiliate
    {
        return request()->cookie('resrv_afid') ? Affiliate::where('code', request()->cookie('resrv_afid'))->first() : null;
    }
}

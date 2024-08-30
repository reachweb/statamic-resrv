<?php

namespace Reach\StatamicResrv\Data;

use Reach\StatamicResrv\Models\Affiliate;

class ReservationData
{
    public function __construct(
        public ?Affiliate $affiliate = null,
        public ?string $coupon = null) {}

    public function hasAffiliate(): bool
    {
        return $this->affiliate !== null;
    }

    public function hasCoupon(): bool
    {
        return $this->coupon !== null;
    }
}

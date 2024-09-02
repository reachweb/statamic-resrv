<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Reach\StatamicResrv\Models\Reservation;

class CouponUpdated
{
    use Dispatchable;

    public function __construct(
        public Reservation $reservation,
        public string $coupon,
        public bool $remove = false,
    ) {}
}

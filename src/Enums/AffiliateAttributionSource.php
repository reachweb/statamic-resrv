<?php

namespace Reach\StatamicResrv\Enums;

/**
 * How an affiliate got attributed to a reservation. Only cookie attributions (the customer
 * arrived through the affiliate's link) may unlock the skip-payment checkout path — a coupon
 * code can circulate publicly, so coupon attributions earn commission but never skip payment.
 */
enum AffiliateAttributionSource: string
{
    case Cookie = 'cookie';

    case Coupon = 'coupon';
}

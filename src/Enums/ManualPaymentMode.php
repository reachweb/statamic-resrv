<?php

namespace Reach\StatamicResrv\Enums;

/**
 * Requested amount of a manual reservation: Standard mirrors the frontend checkout,
 * Full charges the whole total, Custom a validated admin-entered amount.
 */
enum ManualPaymentMode: string
{
    case Standard = 'standard';
    case Full = 'full';
    case Custom = 'custom';
}

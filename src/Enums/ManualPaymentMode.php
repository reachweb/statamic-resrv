<?php

namespace Reach\StatamicResrv\Enums;

/**
 * How the requested amount of a manual (admin-created) reservation is determined:
 * Standard mirrors what the frontend checkout would charge, Full charges the whole
 * total, Custom charges a validated admin-entered amount.
 */
enum ManualPaymentMode: string
{
    case Standard = 'standard';
    case Full = 'full';
    case Custom = 'custom';
}

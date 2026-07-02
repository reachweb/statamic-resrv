<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationLogReason: string
{
    case CheckoutStarted = 'checkout_started';

    case CheckoutConfirmed = 'checkout_confirmed';

    case WebhookConfirmed = 'webhook_confirmed';

    case CheckoutCancelled = 'checkout_cancelled';

    case Expired = 'expired';

    case CpRefund = 'cp_refund';

    public function label(): string
    {
        return match ($this) {
            self::CheckoutStarted => __('Checkout started'),
            self::CheckoutConfirmed => __('Confirmed at checkout'),
            self::WebhookConfirmed => __('Confirmed by payment webhook'),
            self::CheckoutCancelled => __('Cancelled at checkout'),
            self::Expired => __('Expired'),
            self::CpRefund => __('Refunded in the Control Panel'),
        };
    }
}

<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationLogReason: string
{
    case CheckoutStarted = 'checkout_started';

    case CheckoutConfirmed = 'checkout_confirmed';

    case WebhookConfirmed = 'webhook_confirmed';

    case CpConfirmed = 'cp_confirmed';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    case CpRefund = 'cp_refund';

    public function label(): string
    {
        return match ($this) {
            self::CheckoutStarted => __('Checkout started'),
            self::CheckoutConfirmed => __('Confirmed at checkout'),
            self::WebhookConfirmed => __('Confirmed by payment webhook'),
            self::CpConfirmed => __('Confirmed in the Control Panel'),
            self::Cancelled => __('Reservation cancelled'),
            self::Expired => __('Expired'),
            self::CpRefund => __('Refunded in the Control Panel'),
        };
    }
}

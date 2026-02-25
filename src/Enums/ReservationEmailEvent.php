<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationEmailEvent: string
{
    case CustomerConfirmed = 'customer_confirmed';
    case AdminMade = 'admin_made';
    case CustomerRefunded = 'customer_refunded';
    case CustomerAbandoned = 'customer_abandoned';

    public static function values(): array
    {
        return array_map(static fn (self $event) => $event->value, self::cases());
    }
}

<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'refunded';
    case COMPLETED = 'completed';
}
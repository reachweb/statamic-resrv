<?php

namespace Reach\StatamicResrv\Enums;

enum AvailabilityChangeReason: string
{
    case ReservationCreated = 'reservation_created';

    case ReservationExpired = 'reservation_expired';

    case ReservationCancelled = 'reservation_cancelled';

    case ReservationRefunded = 'reservation_refunded';

    case CpEdit = 'cp_edit';

    case CpDelete = 'cp_delete';

    case StuckPendingCleared = 'stuck_pending_cleared';

    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::ReservationCreated => __('Reservation created'),
            self::ReservationExpired => __('Reservation expired'),
            self::ReservationCancelled => __('Reservation cancelled'),
            self::ReservationRefunded => __('Reservation refunded'),
            self::CpEdit => __('Edited in the Control Panel'),
            self::CpDelete => __('Deleted in the Control Panel'),
            self::StuckPendingCleared => __('Stuck pending cleared'),
            self::Import => __('Data import'),
        };
    }
}

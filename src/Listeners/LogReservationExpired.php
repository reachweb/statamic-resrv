<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationExpired
{
    public function __construct(protected ActivityLog $activityLog) {}

    public function handle(ReservationExpired $event): void
    {
        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: ReservationStatus::PENDING,
            to: ReservationStatus::EXPIRED,
            reason: ReservationLogReason::Expired,
        );
    }
}

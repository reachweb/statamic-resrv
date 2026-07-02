<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationCancelled
{
    public function __construct(protected ActivityLog $activityLog) {}

    public function handle(ReservationCancelled $event): void
    {
        // The in-memory model may still carry the enum it was created with, while a
        // re-fetched row carries the plain string — normalise both.
        $status = $event->reservation->status;
        $status = $status instanceof ReservationStatus ? $status : ReservationStatus::from($status);

        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: $status,
            to: $status,
            reason: ReservationLogReason::CheckoutCancelled,
        );
    }
}

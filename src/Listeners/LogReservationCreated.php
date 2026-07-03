<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationCreated
{
    public function __construct(protected ActivityLog $activityLog) {}

    public function handle(ReservationCreated $event): void
    {
        // The in-memory model may still carry the enum it was created with, while a
        // re-fetched row carries the plain string — normalise both.
        $status = $event->reservation->status;

        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: null,
            to: $status instanceof ReservationStatus ? $status : ReservationStatus::from($status),
            reason: ReservationLogReason::CheckoutStarted,
        );
    }
}

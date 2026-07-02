<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationCancelled
{
    public function __construct(protected ActivityLog $activityLog) {}

    /**
     * Nothing in the addon dispatches ReservationCancelled — it is a public extension
     * point for site-level cancellation flows, so the reason stays generic.
     */
    public function handle(ReservationCancelled $event): void
    {
        // The in-memory model may still carry the enum it was created with, while a
        // re-fetched row carries the plain string — normalise both. tryFrom, not from:
        // site code may have written a status outside the enum before dispatching, and
        // a log gap must never break the dispatching flow with a ValueError.
        $status = $event->reservation->status;

        if (! $status instanceof ReservationStatus) {
            $status = is_string($status) ? ReservationStatus::tryFrom($status) : null;
        }

        if ($status === null) {
            return;
        }

        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: $event->reservation->lastTransitionFrom ?? $status,
            to: $status,
            reason: ReservationLogReason::Cancelled,
        );
    }
}

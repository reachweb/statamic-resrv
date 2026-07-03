<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationConfirmed
{
    public function __construct(protected ActivityLog $activityLog) {}

    public function handle(ReservationConfirmed $event): void
    {
        // The in-memory model may still carry the enum it was created with, while a
        // re-fetched row carries the plain string — normalise both.
        $status = $event->reservation->status;

        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: $event->reservation->lastTransitionFrom ?? ReservationStatus::PENDING,
            to: $status instanceof ReservationStatus ? $status : ReservationStatus::from($status),
            reason: $event->via === ReservationConfirmed::VIA_WEBHOOK
                ? ReservationLogReason::WebhookConfirmed
                : ReservationLogReason::CheckoutConfirmed,
            context: $event->payment,
        );
    }
}

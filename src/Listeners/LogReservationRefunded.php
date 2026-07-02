<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Support\ActivityLog;

class LogReservationRefunded
{
    public function __construct(protected ActivityLog $activityLog) {}

    /**
     * Must stay synchronous: cpActor() resolves the acting CP user from the current
     * request, and lastTransitionFrom lives only on the dispatched instance.
     */
    public function handle(ReservationRefunded $event): void
    {
        $this->activityLog->logReservation(
            reservation: $event->reservation,
            from: $event->reservation->lastTransitionFrom,
            to: ReservationStatus::REFUNDED,
            reason: ReservationLogReason::CpRefund,
            context: ['amount' => $event->reservation->payment->format()],
            actor: $this->activityLog->cpActor(),
        );
    }
}

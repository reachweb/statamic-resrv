<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Mail\ReservationCancelled as ReservationCancelledMail;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

/**
 * The AdminCancelled email event is only wired to customer-initiated cancellations,
 * so hold-lapsed cancellations notify admins through their own listener.
 */
class SendHoldLapsedAdminEmail
{
    public function handle(ReservationCancelled $event): void
    {
        // A lapsed hold whose payment was in flight carries CONTEXT_PAYMENT_IN_FLIGHT instead, so
        // the sweep's origin rides on holdLapsed. Other in-flight cancellations (control panel)
        // stay silent to admins here.
        if ($event->context !== ReservationCancelled::CONTEXT_HOLD_LAPSED && ! $event->holdLapsed) {
            return;
        }

        app(ReservationEmailDispatcher::class)->send(
            $event->reservation,
            ReservationEmailEvent::AdminCancelled,
            new ReservationCancelledMail($event->reservation, $event->context),
        );
    }
}

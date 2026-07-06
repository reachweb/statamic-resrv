<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Mail\ReservationCancelled as ReservationCancelledMail;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

/**
 * The AdminCancelled email event is only wired to customer-initiated cancellations
 * (ReservationCancelledByCustomer), so hold-lapsed cancellations notify admins through
 * their own listener — both parties must hear about a lapsed hold, whichever code path
 * cancels it.
 */
class SendHoldLapsedAdminEmail
{
    public function handle(ReservationCancelled $event): void
    {
        if ($event->context !== ReservationCancelled::CONTEXT_HOLD_LAPSED) {
            return;
        }

        app(ReservationEmailDispatcher::class)->send(
            $event->reservation,
            ReservationEmailEvent::AdminCancelled,
            new ReservationCancelledMail($event->reservation, $event->context),
        );
    }
}

<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\Mailable;
use Reach\StatamicResrv\Models\Reservation;

class ReservationEmailDispatcher
{
    public function __construct(
        protected ReservationEmailConfigResolver $configResolver,
        protected ReservationEmailRecipientResolver $recipientResolver,
    ) {}

    public function send(Reservation $reservation, ReservationEmailEvent|string $event, Mailable $mailable): bool
    {
        $eventKey = $event instanceof ReservationEmailEvent ? $event->value : (string) $event;
        $config = $this->configResolver->resolveForEvent($reservation, $event);

        if (! data_get($config, 'enabled', true)) {
            Log::debug('Resrv email skipped because event is disabled.', [
                'event' => $eventKey,
                'reservation_id' => $reservation->id,
            ]);

            return false;
        }

        $recipients = $this->recipientResolver->resolve($reservation, data_get($config, 'recipients', []));
        if (count($recipients) === 0) {
            Log::debug('Resrv email skipped because no valid recipients were resolved.', [
                'event' => $eventKey,
                'reservation_id' => $reservation->id,
            ]);

            return false;
        }

        $mailable->applyResrvEmailConfig($config);

        Mail::to($recipients)->send($mailable);

        return true;
    }
}

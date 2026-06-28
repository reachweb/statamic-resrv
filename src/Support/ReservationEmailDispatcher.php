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

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(clone $mailable);
        }

        return true;
    }

    /**
     * Send to an explicit recipient list, ignoring the event's configured recipients but
     * still applying its from/subject/markdown overrides. Used by manual actions (e.g. an
     * admin resending the confirmation) that must always reach a specific address regardless
     * of how the event's recipients are configured.
     *
     * @param  array<int, string|null>  $recipients
     */
    public function sendToRecipients(Reservation $reservation, ReservationEmailEvent|string $event, Mailable $mailable, array $recipients): bool
    {
        $recipients = collect($recipients)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            Log::debug('Resrv email skipped because no valid forced recipients were provided.', [
                'event' => $event instanceof ReservationEmailEvent ? $event->value : (string) $event,
                'reservation_id' => $reservation->id,
            ]);

            return false;
        }

        $config = $this->configResolver->resolveForEvent($reservation, $event);

        $mailable->applyResrvEmailConfig($config);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(clone $mailable);
        }

        return true;
    }
}

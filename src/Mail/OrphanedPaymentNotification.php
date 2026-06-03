<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

/**
 * Sent when a successful payment webhook arrives for a reservation that is already in a
 * terminal state (EXPIRED, REFUNDED, or PARTNER). The charge exists on the gateway but
 * there is no live reservation to attach it to — surface to admins so they can issue a
 * manual refund in the gateway dashboard.
 */
class OrphanedPaymentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $paymentIntentId,
        public ?string $stripeEventId = null,
    ) {
        $this->subject($this->generateSubject($reservation));
    }

    public function build()
    {
        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.orphaned-payment'));
    }

    /**
     * Notify admins when a succeeded payment lands on a reservation that can no longer be
     * confirmed (EXPIRED/REFUNDED/PARTNER), leaving the charge orphaned. Returns true when an
     * orphan was detected, so gateways can short-circuit on it.
     */
    public static function notifyIfOrphaned(Reservation $reservation, string $paymentIntentId, ?string $stripeEventId = null): bool
    {
        if (! in_array($reservation->status, [
            ReservationStatus::EXPIRED->value,
            ReservationStatus::REFUNDED->value,
            ReservationStatus::PARTNER->value,
        ], true)) {
            return false;
        }

        Log::warning('Succeeded payment for a non-confirmable reservation — manual refund likely required.', [
            'reservation_id' => $reservation->id,
            'reservation_status' => $reservation->status,
            'payment_intent_id' => $paymentIntentId,
        ]);

        self::dispatchFor($reservation, $paymentIntentId, $stripeEventId);

        return true;
    }

    /**
     * Build and dispatch an orphan-payment notification via the package's email dispatcher.
     * Shared entry-point so gateway implementations don't each reimplement the try/catch.
     */
    public static function dispatchFor(Reservation $reservation, string $paymentIntentId, ?string $stripeEventId = null): void
    {
        // Stripe redelivers webhooks for up to ~3 days, so an orphan charge would otherwise re-email
        // admins on every retry. Notify only once per (reservation, payment intent).
        $dedupeKey = 'resrv_orphan_notified:'.$reservation->id.':'.$paymentIntentId;

        if (Cache::has($dedupeKey)) {
            return;
        }

        try {
            $sent = app(ReservationEmailDispatcher::class)->send(
                $reservation,
                ReservationEmailEvent::AdminOrphanedPayment,
                new self($reservation, $paymentIntentId, $stripeEventId),
            );

            // Only mark as notified once a message actually went out, so a disabled/no-recipient run
            // doesn't permanently suppress a later genuine notification.
            if ($sent) {
                Cache::put($dedupeKey, true, now()->addDays(4));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch orphaned payment notification', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateSubject(Reservation $reservation): string
    {
        return 'Orphaned payment detected — Reservation #'.$reservation->id.' ['.$reservation->status.']';
    }
}

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
 * Sent when a gateway charge needs manual reconciliation against its reservation. In every case
 * the succeeded webhook sees a non-payable status and no-ops, so this email is the only signal.
 * Cases, distinguished by $context:
 *
 * - default (orphan): a successful webhook arrived for a terminal reservation
 *   (EXPIRED/REFUNDED/CANCELLED/PARTNER) — admins should refund the charge.
 * - CONTEXT_OUT_OF_BAND_DUPLICATE: an out-of-band CP confirm raced an intent that already
 *   captured money — the booking is paid TWICE.
 * - CONTEXT_OUT_OF_BAND_STILL_PAYABLE: the intent could not be voided and remains completable
 *   with the customer's client secret — admins must cancel it in the gateway dashboard.
 * - CONTEXT_OUT_OF_BAND_UNVERIFIED: the gateway was unreachable, so the intent's state is
 *   unknown — admins must check it in the gateway dashboard.
 * - CONTEXT_CANCELLED_CAPTURED: a cancellation found its intent had already captured (or was
 *   capturing) money — admins should refund the charge.
 * - CONTEXT_CANCELLED_UNVERIFIED: a cancellation could not void or inspect its intent because
 *   the recorded gateway is no longer configured; its webhook route 404s, so orphan detection
 *   can never fire — this email is the only signal.
 */
class OrphanedPaymentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public const CONTEXT_OUT_OF_BAND_DUPLICATE = 'out_of_band_duplicate';

    public const CONTEXT_OUT_OF_BAND_STILL_PAYABLE = 'out_of_band_still_payable';

    public const CONTEXT_OUT_OF_BAND_UNVERIFIED = 'out_of_band_unverified';

    public const CONTEXT_CANCELLED_CAPTURED = 'cancelled_captured';

    public const CONTEXT_CANCELLED_UNVERIFIED = 'cancelled_unverified';

    public function __construct(
        public Reservation $reservation,
        public string $paymentIntentId,
        public ?string $stripeEventId = null,
        public ?string $context = null,
    ) {
        $this->subject($this->generateSubject($reservation));
    }

    public function build()
    {
        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.orphaned-payment'));
    }

    /**
     * Notify admins when a succeeded payment lands on a reservation that can no longer be
     * confirmed (EXPIRED/REFUNDED/CANCELLED/PARTNER), leaving the charge orphaned. Returns
     * true when an orphan was detected, so gateways can short-circuit on it. CANCELLED
     * covers the hold-lapse race (sweep cancels while the payment is in flight).
     */
    public static function notifyIfOrphaned(Reservation $reservation, string $paymentIntentId, ?string $stripeEventId = null): bool
    {
        if (! in_array($reservation->status, [
            ReservationStatus::EXPIRED->value,
            ReservationStatus::REFUNDED->value,
            ReservationStatus::CANCELLED->value,
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
    public static function dispatchFor(Reservation $reservation, string $paymentIntentId, ?string $stripeEventId = null, ?string $context = null): void
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
                new self($reservation, $paymentIntentId, $stripeEventId, $context),
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
        if ($this->context === self::CONTEXT_OUT_OF_BAND_DUPLICATE) {
            return 'Possible duplicate payment — Reservation #'.$reservation->id.' ['.$reservation->status.']';
        }

        if ($this->context === self::CONTEXT_OUT_OF_BAND_STILL_PAYABLE) {
            return 'Open payment intent could not be cancelled — Reservation #'.$reservation->id.' ['.$reservation->status.']';
        }

        if ($this->context === self::CONTEXT_OUT_OF_BAND_UNVERIFIED || $this->context === self::CONTEXT_CANCELLED_UNVERIFIED) {
            return 'Payment intent could not be verified — Reservation #'.$reservation->id.' ['.$reservation->status.']';
        }

        if ($this->context === self::CONTEXT_CANCELLED_CAPTURED) {
            return 'Captured payment on a cancelled reservation — Reservation #'.$reservation->id.' ['.$reservation->status.']';
        }

        return 'Orphaned payment detected — Reservation #'.$reservation->id.' ['.$reservation->status.']';
    }
}

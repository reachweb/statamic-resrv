<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
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
     * Build and dispatch an orphan-payment notification via the package's email dispatcher.
     * Shared entry-point so gateway implementations don't each reimplement the try/catch.
     */
    public static function dispatchFor(Reservation $reservation, string $paymentIntentId, ?string $stripeEventId = null): void
    {
        try {
            app(ReservationEmailDispatcher::class)->send(
                $reservation,
                ReservationEmailEvent::AdminOrphanedPayment,
                new self($reservation, $paymentIntentId, $stripeEventId),
            );
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

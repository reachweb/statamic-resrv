<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

/**
 * The confirm half of a gateway's verifyPayment(). The gateway verifies the request, finds the
 * reservation and maps the provider's event; for a payment that succeeded it calls
 * confirmFromGateway(), which decides whether the payment confirms the booking or needs an admin.
 * See UPGRADE-PAYMENT-GATEWAYS.md Step 4.
 */
trait ConfirmsGatewayPayments
{
    /**
     * Confirm the reservation for a payment the provider reports as succeeded, or alert the admins
     * when the money can't attach to it. Returns true only when this call confirmed it.
     *
     * @param  string  $paymentId  The provider's id for this payment.
     * @param  bool  $stale  The reservation was found through the payment's metadata because its
     *                       payment_id no longer names this payment. Pass true only for a payment
     *                       known to be this site's.
     * @param  int|string|null  $amount  What the provider charged, in minor units as Price::raw()
     *                                   gives them, or null when the provider doesn't report it.
     * @param  string|null  $eventId  The provider's event id, shown in the admin alert.
     */
    protected function confirmFromGateway(Reservation $reservation, string $paymentId, bool $stale = false, int|string|null $amount = null, ?string $eventId = null): bool
    {
        // The customer moved on (went back, switched gateway, paid another way) before this payment
        // succeeded. It no longer matches the booking's amount or gateway, and a booking confirmed
        // since is now paid twice, so leave the reservation untouched and alert for a manual refund.
        if ($stale) {
            Log::warning('Payment succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                'gateway' => $this->name(),
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $paymentId,
                'current_payment_id' => $reservation->payment_id,
                'current_payment_gateway' => $reservation->payment_gateway,
            ]);

            OrphanedPaymentNotification::dispatchFor($reservation, $paymentId, $eventId);

            return false;
        }

        // A redelivery for the payment that confirmed the booking.
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return false;
        }

        // Terminal reservation: the charge can't attach to a live booking, so notify and stop.
        if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $paymentId, $eventId)) {
            return false;
        }

        // Defense-in-depth: refuse to confirm if the charged amount no longer matches what's owed.
        if ($amount !== null) {
            $expectedAmount = $reservation->payment->add($reservation->payment_surcharge)->raw();

            if ((int) $amount !== (int) $expectedAmount) {
                Log::warning('Payment webhook amount does not match the reservation total; not confirming.', [
                    'gateway' => $this->name(),
                    'reservation_id' => $reservation->id,
                    'payment_intent_id' => $paymentId,
                    'amount_received' => $amount,
                    'expected_amount' => $expectedAmount,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $paymentId, $eventId);

                return false;
            }
        }

        // Accepts PENDING (checkout) and AWAITING_PAYMENT (payment link) rows alike.
        if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
            ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                'gateway' => $reservation->payment_gateway ?: $this->name(),
                'payment_id' => $paymentId,
            ]);

            return true;
        }

        // Lost the confirm race: the row changed under the lock (ExpireReservations expired it, or
        // the hold-lapse sweep cancelled it). Surface the charge if it is now orphaned.
        $reservation->refresh();
        OrphanedPaymentNotification::notifyIfOrphaned($reservation, $paymentId, $eventId);

        return false;
    }
}

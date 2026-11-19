<?php

namespace Reach\StatamicResrv\Http\Payment;

use Closure;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\PaymentSettlement;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
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
     * @param  Closure(Reservation): void|null  $inTransaction  Runs on the locked row before the
     *                                                          confirming save, so a change to it,
     *                                                          such as the provider's capture id
     *                                                          in payment_id, is saved with CONFIRMED.
     */
    protected function confirmFromGateway(Reservation $reservation, string $paymentId, bool $stale = false, int|string|null $amount = null, ?string $eventId = null, ?Closure $inTransaction = null): bool
    {
        // The customer moved on (went back, switched gateway, paid another way) before this payment
        // succeeded. It no longer matches the booking's amount or gateway, and a booking confirmed
        // since is now paid twice, so leave the reservation untouched and alert for a manual refund.
        if ($stale) {
            $this->notifyAbandonedPayment($reservation, $paymentId, $eventId);

            return false;
        }

        // Already confirmed: a redelivery of the payment that confirmed it does nothing.
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            $this->notifyIfConfirmedByAnotherPayment($reservation, $paymentId, $eventId);

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

        // transitionTo() re-checks only the status under the lock. The customer may also have moved on
        // to another payment since the lookup, such as a gateway switch, so a payment_id that now names
        // another payment makes this one stale too. A blank one is a checkout voiding this payment, which
        // restores it when the void finds it paid, so it still confirms.
        $lookedUp = $reservation->payment_id;
        $confirmFresh = function (Reservation $fresh) use ($lookedUp, $inTransaction) {
            if (filled($fresh->payment_id) && $fresh->payment_id !== $lookedUp) {
                throw new ReservationNoLongerPayable($fresh->id);
            }

            // Paid through this gateway, even while the void blanks payment_id.
            $fresh->payment_settlement = PaymentSettlement::Gateway;

            if ($inTransaction) {
                $inTransaction($fresh);
            }
        };

        try {
            // Accepts PENDING (checkout) and AWAITING_PAYMENT (payment link) rows alike.
            $confirmed = $reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true, inTransaction: $confirmFresh);
        } catch (ReservationNoLongerPayable) {
            $this->notifyAbandonedPayment($reservation->refresh(), $paymentId, $eventId);

            return false;
        }

        if ($confirmed) {
            ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                'gateway' => $reservation->payment_gateway ?: $this->name(),
                'payment_id' => $paymentId,
            ]);

            return true;
        }

        // Lost the confirm race: the row changed under the lock. ExpireReservations or the
        // hold-lapse sweep may have ended it, or another payment confirmed it.
        $reservation->refresh();

        if (! $this->notifyIfConfirmedByAnotherPayment($reservation, $paymentId, $eventId)) {
            OrphanedPaymentNotification::notifyIfOrphaned($reservation, $paymentId, $eventId);
        }

        return false;
    }

    /** Alert for a payment the customer left behind, which no longer attaches to the booking. */
    private function notifyAbandonedPayment(Reservation $reservation, string $paymentId, ?string $eventId): void
    {
        Log::warning('Payment succeeded after being abandoned by the customer — manual reconciliation may be required.', [
            'gateway' => $this->name(),
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $paymentId,
            'current_payment_id' => $reservation->payment_id,
            'current_payment_gateway' => $reservation->payment_gateway,
        ]);

        OrphanedPaymentNotification::dispatchFor($reservation, $paymentId, $eventId);
    }

    /**
     * Alert when the booking is confirmed, but not by this payment: payment_id names another one
     * (the customer switched gateway and paid with it), or an admin marked the booking as paid,
     * which settles it out of band and keeps this payment's id. This charge is then a second payment.
     */
    private function notifyIfConfirmedByAnotherPayment(Reservation $reservation, string $paymentId, ?string $eventId): bool
    {
        if ($reservation->status !== ReservationStatus::CONFIRMED->value
            || ($reservation->payment_id === $paymentId && $reservation->payment_settlement === PaymentSettlement::Gateway)) {
            return false;
        }

        Log::warning('Payment succeeded for a reservation another payment already confirmed — manual refund likely required.', [
            'gateway' => $this->name(),
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $paymentId,
            'current_payment_id' => $reservation->payment_id,
            'current_payment_gateway' => $reservation->payment_gateway,
        ]);

        OrphanedPaymentNotification::dispatchFor($reservation, $paymentId, $eventId, OrphanedPaymentNotification::CONTEXT_CONFIRMED_BY_ANOTHER_PAYMENT);

        return true;
    }
}

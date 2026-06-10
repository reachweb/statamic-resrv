<?php

namespace Reach\StatamicResrv\Support;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\CancellationNotAllowed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;

class ReservationRefundProcessor
{
    /**
     * Refund a reservation: return the charge through the payment gateway when one exists,
     * transition to REFUNDED and dispatch ReservationRefunded (which restores availability
     * and sends the refund emails). Shared by the CP refund action and the customer-facing
     * ReservationStatus component so both flows stay behaviorally identical.
     *
     * The gateway call rides inside transitionTo()'s row lock — unlike expire(), which
     * releases its lock before the network call — because a concurrent caller must block
     * until the winner commits and then observe REFUNDED, so the charge is refunded exactly
     * once; and a gateway failure must roll back the status change so money is never marked
     * returned when it wasn't.
     *
     * Returns true when the status changed; false when a concurrent caller already refunded
     * the reservation (the event only fires for the caller that won, so side effects like
     * IncreaseAvailability never run twice).
     *
     * @throws InvalidStateTransition when the current status cannot transition to REFUNDED
     * @throws RefundFailedException when the payment gateway rejects the refund
     */
    public function refund(Reservation $reservation): bool
    {
        $changed = $reservation->transitionTo(
            ReservationStatus::REFUNDED,
            inTransaction: fn (Reservation $fresh) => $this->refundThroughGateway($fresh),
        );

        if ($changed) {
            ReservationRefunded::dispatch($reservation);
        }

        return $changed;
    }

    /**
     * Customer-initiated cancellation: the self-cancel policy guard, the refund, and the
     * "cancelled by the customer" admin notification in one place, so every surface that
     * lets a customer cancel behaves identically. Availability restore and the customer
     * refund email still come from ReservationRefunded via refund().
     *
     * @throws CancellationNotAllowed when the booking is outside its free cancellation window
     * @throws InvalidStateTransition when the current status cannot transition to REFUNDED
     * @throws RefundFailedException when the payment gateway rejects the refund
     */
    public function cancelByCustomer(Reservation $reservation): bool
    {
        if (! $reservation->canBeCancelledByCustomer()) {
            throw new CancellationNotAllowed($reservation->id);
        }

        $changed = $this->refund($reservation);

        if ($changed) {
            ReservationCancelledByCustomer::dispatch($reservation);
        }

        return $changed;
    }

    /**
     * Partner (affiliate skip-payment) and zero-payment reservations never create a payment
     * intent, so payment_id stays '' — there is no charge on the gateway to refund. Skip the
     * gateway call entirely or Stripe rejects the empty payment_intent and blocks the refund.
     * Anything else with an empty payment_id (e.g. a PENDING row whose cancelled intent may
     * still carry an orphaned charge) keeps going through the gateway so the failure surfaces
     * to the caller instead of silently marking captured money as refunded.
     */
    protected function refundThroughGateway(Reservation $fresh): void
    {
        $gatewayHoldsNoCharge = ($fresh->payment_id === '' || $fresh->payment_id === null)
            && ($fresh->status === ReservationStatus::PARTNER->value || $fresh->payment->isZero());

        if ($gatewayHoldsNoCharge) {
            return;
        }

        $manager = app(PaymentGatewayManager::class);
        try {
            $payment = $manager->forReservation($fresh);
        } catch (\InvalidArgumentException $e) {
            $payment = $manager->gateway();
        }
        $payment->refund($fresh);
    }
}

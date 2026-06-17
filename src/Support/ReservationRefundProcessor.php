<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\CancellationNotAllowed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
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
            $this->dispatchCommitted(ReservationRefunded::class, $reservation);
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
     *                                or its gateway cannot refund automatically (e.g. offline
     *                                bank transfer — money would be marked returned without moving)
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
            $this->dispatchCommitted(ReservationCancelledByCustomer::class, $reservation);
        }

        return $changed;
    }

    /**
     * Dispatch a post-commit event without letting a throwing synchronous listener
     * (availability restore, emails) masquerade as a failed refund: by the time these
     * events fire, the status change — and any gateway refund — has already committed,
     * so callers must be told the refund succeeded. The failed side effect is logged
     * for manual reconciliation; retrying the refund could not rerun it anyway.
     */
    protected function dispatchCommitted(string $event, Reservation $reservation): void
    {
        try {
            $event::dispatch($reservation);
        } catch (\Throwable $e) {
            Log::error('Post-refund side effects failed; manual reconciliation may be required.', [
                'reservation_id' => $reservation->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reservations whose gateway holds no charge (partner / zero-payment) skip the gateway
     * call entirely or Stripe rejects the empty payment_intent and blocks the refund.
     * Anything else with an empty payment_id (e.g. a PENDING row whose cancelled intent may
     * still carry an orphaned charge) keeps going through the gateway so the failure surfaces
     * to the caller instead of silently marking captured money as refunded.
     */
    protected function refundThroughGateway(Reservation $fresh): void
    {
        if ($fresh->gatewayHoldsNoCharge()) {
            return;
        }

        $refund = $fresh->resolvePaymentGateway()->refund($fresh);

        // Logged inside the still-open transaction so a money-moved-but-commit-failed window
        // (deadlock victim, lock-wait timeout, or a dropped connection between this call and COMMIT —
        // which surfaces to callers as a generic failure while the status rolls back to live) still
        // leaves a breadcrumb that the gateway actually refunded. Without it, that case is logged
        // identically to a refund that never reached the gateway. Matches the manual-reconciliation
        // logging expire() and dispatchCommitted() already emit.
        Log::info('Gateway refund processed for reservation.', [
            'reservation_id' => $fresh->id,
            'payment_id' => $fresh->payment_id,
            'refund_id' => is_object($refund) ? ($refund->id ?? null) : null,
        ]);
    }
}

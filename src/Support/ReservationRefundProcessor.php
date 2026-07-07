<?php

namespace Reach\StatamicResrv\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\CancellationNotAllowed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;

class ReservationRefundProcessor
{
    /**
     * Refund a reservation: return the charge through the payment gateway, transition to
     * REFUNDED and dispatch ReservationRefunded (which restores availability and sends the
     * refund emails). No-charge bookings (partner / zero-payment) route to
     * cancelWithoutRefund() instead — nothing can be returned, so they end CANCELLED;
     * except PENDING no-charge rows, which are abandoned checkouts rather than bookings
     * and are expired instead (CANCELLED is unreachable from PENDING, and the cancelled
     * chain would email a cancellation notice for a booking that never completed).
     * Shared by the CP refund action and the customer-facing ReservationStatus component
     * so both flows stay behaviorally identical.
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
     * @throws UnknownPaymentGateway when the recorded gateway is no longer configured — the
     *                               transaction rolls back and the charge must be refunded manually
     */
    public function refund(Reservation $reservation): bool
    {
        // No charge ever reached a gateway (partner / zero-payment booking): there is no
        // money to return, so terminating it is a cancellation, not a refund — REFUNDED is
        // reserved for reservations whose charge actually went back. transitionTo() still
        // re-validates the status under the row lock, so a stale in-memory read here can
        // only end in InvalidStateTransition, never a wrong terminal state.
        if ($reservation->gatewayHoldsNoCharge()) {
            // A PENDING no-charge row (abandoned zero-payment checkout) releases its hold the
            // way the expiry sweep would: expire() re-checks PENDING under its own row lock
            // and syncs this model on success. If a concurrent confirm won the race the model
            // stays stale, and the cancel path below re-validates against the fresh row.
            if ($reservation->status === ReservationStatus::PENDING->value) {
                $reservation->expire();

                if ($reservation->status === ReservationStatus::EXPIRED->value) {
                    return true;
                }
            }

            return $this->cancelWithoutRefund($reservation);
        }

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
     * Terminate a booking without returning any money: transition to CANCELLED and dispatch
     * ReservationCancelled (commission handling, availability restore, activity log). Used for
     * no-charge bookings voided from the CP and for customer cancellations outside the free
     * cancellation window, where the payment stays with the business.
     *
     * @param  ?Closure  $inTransaction  Runs on the locked fresh row before the status
     *                                   write — throw to abort (e.g. an origin-status
     *                                   re-check by sweeps whose target is reachable
     *                                   from more than one state).
     * @param  bool  $cancelOpenIntent  Also void any open payment intent (awaiting-payment
     *                                  bookings) — after the transition commits, never
     *                                  inside the lock, and tolerantly: an unreachable
     *                                  gateway leaves an intent that dies of old age.
     *
     * @throws InvalidStateTransition when the current status cannot transition to CANCELLED
     */
    public function cancelWithoutRefund(Reservation $reservation, ?string $context = null, ?Closure $inTransaction = null, bool $cancelOpenIntent = false): bool
    {
        $changed = $reservation->transitionTo(ReservationStatus::CANCELLED, inTransaction: $inTransaction);

        if ($changed) {
            $this->dispatchCommitted(ReservationCancelled::class, $reservation, $context);

            // Read payment_id/gateway from the just-transitioned row, not from a snapshot taken
            // before transitionTo() acquired its lock: the pay page may have written payment_id
            // after this model was hydrated but before the lock, and transitionTo() syncs the
            // locked, committed row back onto the model. A pre-lock capture would use a stale
            // (often empty) id and silently skip cancelling the customer's live intent.
            if ($cancelOpenIntent) {
                $this->cancelOpenIntentQuietly($reservation);
            }
        }

        return $changed;
    }

    /**
     * Void any open payment intent recorded on the reservation, tolerating gateway failures.
     * Read the ids from the current model state, which callers must have synced to the
     * committed row (via transitionTo()), so a concurrent pay-page write is not missed.
     * A no-op when no intent was ever created (empty payment_id/gateway).
     */
    public function cancelOpenIntentQuietly(Reservation $reservation): void
    {
        $paymentId = (string) $reservation->payment_id;
        $paymentGateway = (string) $reservation->payment_gateway;

        if ($paymentId !== '' && $paymentGateway !== '') {
            $this->cancelPaymentIntentQuietly($reservation, $paymentId, $paymentGateway);
        }
    }

    /**
     * Void an open payment intent after a terminal transition has committed, tolerating
     * gateway failures: local state is already correct, so the error is only logged for
     * manual reconciliation (mirrors Reservation::expire()).
     */
    protected function cancelPaymentIntentQuietly(Reservation $reservation, string $paymentId, string $paymentGateway): void
    {
        try {
            app(PaymentGatewayManager::class)
                ->gateway($paymentGateway)
                ->cancelPaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            Log::error('Failed to cancel the payment intent after cancelling the reservation; manual reconciliation may be required.', [
                'reservation_id' => $reservation->id,
                'payment_id' => $paymentId,
                'payment_gateway' => $paymentGateway,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Customer-initiated cancellation: the self-cancel policy guards, the terminal-state
     * routing, and the "cancelled by the customer" admin notification in one place, so
     * every surface that lets a customer cancel behaves identically. Inside the free
     * cancellation window the charge is refunded (REFUNDED); after it closes — or when
     * the policy is non-refundable — the booking is cancelled with the payment retained
     * (CANCELLED). Availability restore and the customer email come from the respective
     * ReservationRefunded / ReservationCancelled chains.
     *
     * @throws CancellationNotAllowed when the booking is not customer-cancellable (not live,
     *                                stay already started, or its gateway cannot self-serve —
     *                                offline/partner/unknown gateways are "contact us" cases)
     * @throws InvalidStateTransition when the current status cannot reach the terminal state
     * @throws RefundFailedException when the payment gateway rejects the refund
     */
    public function cancelByCustomer(Reservation $reservation): bool
    {
        // Server-side gate for the opt-in feature: hiding the buttons is not enforcement —
        // a Livewire action can still be invoked directly.
        if (! config('resrv-config.enable_customer_cancellations')) {
            throw new CancellationNotAllowed($reservation->id);
        }

        $changed = match (true) {
            $reservation->canCancelWithRefund() => $this->refund($reservation),
            $reservation->canCancelWithoutRefund() => $this->cancelWithoutRefund($reservation),
            default => throw new CancellationNotAllowed($reservation->id),
        };

        if ($changed) {
            $this->dispatchCommitted(ReservationCancelledByCustomer::class, $reservation);
        }

        return $changed;
    }

    /**
     * Dispatch a post-commit event without letting a throwing synchronous listener
     * (availability restore, emails) masquerade as a failed refund or cancellation: by
     * the time these events fire, the status change — and any gateway refund — has
     * already committed, so callers must be told the operation succeeded. The failed
     * side effect is logged for manual reconciliation; retrying could not rerun it anyway.
     */
    protected function dispatchCommitted(string $event, Reservation $reservation, mixed ...$args): void
    {
        try {
            $event::dispatch($reservation, ...$args);
        } catch (\Throwable $e) {
            Log::error('Post-commit reservation side effects failed; manual reconciliation may be required.', [
                'reservation_id' => $reservation->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reservations whose gateway holds no charge (partner / zero-payment) skip the gateway
     * call entirely or Stripe rejects the empty payment_intent and blocks the refund. refund()
     * already routes them to cancelWithoutRefund() from the in-memory state; this re-check on
     * the locked fresh row is the race backstop. Anything else with an empty payment_id (e.g.
     * a PENDING row whose cancelled intent may still carry an orphaned charge) keeps going
     * through the gateway so the failure surfaces to the caller instead of silently marking
     * captured money as refunded.
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

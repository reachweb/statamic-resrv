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
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

class ReservationRefundProcessor
{
    /**
     * Intent statuses where money is captured or being captured — the intent can only be
     * refunded, not voided.
     */
    private const MONEY_MOVING_INTENT_STATUSES = ['succeeded', 'processing', 'requires_capture'];

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
     *                                   write — throw to abort (e.g. an origin-status re-check).
     * @param  bool  $cancelOpenIntent  Also void any open payment intent — after the
     *                                  transition commits, never inside the lock, tolerantly;
     *                                  the local reference is dropped only once the gateway
     *                                  confirms the void.
     *
     * @throws InvalidStateTransition when the current status cannot transition to CANCELLED
     */
    public function cancelWithoutRefund(Reservation $reservation, ?string $context = null, ?Closure $inTransaction = null, bool $cancelOpenIntent = false): bool
    {
        $changed = $reservation->transitionTo(ReservationStatus::CANCELLED, inTransaction: $inTransaction);

        if ($changed) {
            $this->dispatchCommitted(ReservationCancelled::class, $reservation, $context);

            // Read payment_id/gateway from the just-transitioned row (transitionTo() syncs the
            // locked committed row): a pre-lock snapshot could miss a pay-page write and
            // silently skip cancelling the customer's live intent.
            if ($cancelOpenIntent) {
                $this->cancelOpenIntentAndClearVerifiedReference($reservation);
            }
        }

        return $changed;
    }

    /**
     * Void any open intent, then drop the local reference only once the gateway confirms it
     * can no longer take money — the cancel-path mirror of settlePaidOutOfBand()'s
     * verify-before-clear. An unverified void KEEPS the reference for reconciliation: a stale
     * payment_id display beats discarding the only handle on a possibly-live charge. No admin
     * notification here: the row is CANCELLED, so a later payment still reaches the webhook's
     * orphan detection (only CONFIRMED rows short-circuit it).
     */
    protected function cancelOpenIntentAndClearVerifiedReference(Reservation $reservation): void
    {
        $paymentId = (string) $reservation->payment_id;
        $paymentGateway = (string) $reservation->payment_gateway;

        if ($paymentId === '' || $paymentGateway === '') {
            return;
        }

        $this->cancelPaymentIntentQuietly($reservation, $paymentId, $paymentGateway);

        try {
            $gateway = $reservation->resolvePaymentGateway();
        } catch (UnknownPaymentGateway $e) {
            return;
        }

        if ($this->intentIsVerifiablyGone($gateway, $reservation, $paymentId)) {
            $reservation->update(['payment_id' => '']);

            return;
        }

        Log::warning('Could not verify the cancelled reservation\'s payment intent was voided; keeping the gateway charge reference for reconciliation.', [
            'reservation_id' => $reservation->id,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Reconcile the gateway after an awaiting-payment reservation is confirmed out of band.
     * Manually-confirmable gateways never minted a real charge. For online gateways, void any
     * leftover intent and drop payment_id so downstream refund/cancel reads a no-gateway-charge
     * settlement — UNLESS the intent captured or is capturing money (a webhook confirm racing
     * this one): then keep the reference so the charge stays refundable, and notify admins of
     * the possible duplicate (the follow-up webhook no-ops on the CONFIRMED row silently).
     * A capture can surface on either read; a read that THROWS leaves the state unknown, so
     * those paths notify too (CONTEXT_OUT_OF_BAND_UNVERIFIED).
     *
     * Callers must have synced the reservation to the committed row (via transitionTo()) first,
     * so a concurrent pay-page write is not missed.
     */
    public function settlePaidOutOfBand(Reservation $reservation): void
    {
        try {
            $gateway = $reservation->resolvePaymentGateway();
        } catch (UnknownPaymentGateway $e) {
            // The recorded gateway is no longer configured — nothing we can safely void or verify.
            return;
        }

        if ($gateway->supportsManualConfirmation()) {
            $this->cancelOpenIntentQuietly($reservation);

            return;
        }

        $paymentId = (string) $reservation->payment_id;

        // No intent was ever created: the empty payment_id already marks a no-gateway-charge booking.
        if ($paymentId === '') {
            return;
        }

        // A money-moving intent means a webhook confirm is racing this manual one: keep the
        // reference so the charge stays refundable, and notify — the succeeded webhook that
        // follows sees CONFIRMED and no-ops, so silence here would hide the double payment.
        try {
            $intent = $gateway->retrievePaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            // State unknown: keep the reference rather than risk dropping a real charge, and
            // notify — the follow-up webhook no-ops on CONFIRMED, so a log line alone would
            // bury the only warning of a silent duplicate.
            $this->notifyPossibleDuplicatePayment(
                $reservation,
                $paymentId,
                'unknown',
                'Could not verify the payment intent while confirming an out-of-band payment; keeping the gateway charge reference — check the intent at the gateway.',
                OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_UNVERIFIED,
                ['error' => $e->getMessage()],
            );

            return;
        }

        if ($intent !== null && in_array($intent->status ?? '', self::MONEY_MOVING_INTENT_STATUSES, true)) {
            $this->notifyPossibleDuplicatePayment(
                $reservation,
                $paymentId,
                (string) $intent->status,
                'Payment intent had already captured (or was capturing) money while confirming an out-of-band payment — possible duplicate payment.',
            );

            return;
        }

        // Void the dead/never-charged intent, but drop the reference only once VERIFIED gone:
        // cancelOpenIntentQuietly swallows transient provider failures, and a silent failure
        // would discard the only reference to a still-live intent. Unverified → keep it so a
        // later refund routes through the gateway with the real id.
        $this->cancelOpenIntentQuietly($reservation);

        try {
            $intent = $gateway->retrievePaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            // Void outcome unknown — the payment may have landed inside the void window; same
            // silent-duplicate exposure as the still-payable branch below, so notify, not just log.
            $this->notifyPossibleDuplicatePayment(
                $reservation,
                $paymentId,
                'unknown',
                'Could not verify the payment intent was cancelled while confirming an out-of-band payment; keeping the gateway charge reference — check the intent at the gateway.',
                OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_UNVERIFIED,
                ['error' => $e->getMessage()],
            );

            return;
        }

        if ($intent === null || ($intent->status ?? '') === 'canceled') {
            $reservation->update(['payment_id' => '']);

            return;
        }

        // The payment landed inside the void window: the same duplicate condition as the
        // pre-void branch, one read later — and the last point the double payment is visible,
        // so it must not degrade to the generic could-not-verify warning below.
        if (in_array($intent->status ?? '', self::MONEY_MOVING_INTENT_STATUSES, true)) {
            $this->notifyPossibleDuplicatePayment(
                $reservation,
                $paymentId,
                (string) $intent->status,
                'Payment intent captured (or began capturing) money while being voided during an out-of-band confirmation — possible duplicate payment.',
            );

            return;
        }

        // The void failed and the intent is still payable: the customer's client secret can
        // still collect the full amount, and the succeeded webhook would no-op on CONFIRMED —
        // admins must be told now to cancel the intent at the gateway.
        $this->notifyPossibleDuplicatePayment(
            $reservation,
            $paymentId,
            (string) ($intent->status ?? ''),
            'Payment intent could not be cancelled while confirming an out-of-band payment and can still collect money — cancel it at the gateway to prevent a duplicate payment.',
            OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_STILL_PAYABLE,
        );
    }

    /**
     * Alert admins to an actual or possible duplicate payment: an out-of-band payment plus a
     * gateway charge that captured, is still payable, or could not be verified. Every caller
     * keeps the charge reference; the succeeded webhook no-ops on CONFIRMED, so this
     * notification is the only reconciliation signal.
     */
    protected function notifyPossibleDuplicatePayment(
        Reservation $reservation,
        string $paymentId,
        string $intentStatus,
        string $logMessage,
        string $context = OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_DUPLICATE,
        array $extraLogContext = [],
    ): void {
        Log::warning($logMessage, [
            'reservation_id' => $reservation->id,
            'payment_id' => $paymentId,
            'intent_status' => $intentStatus,
            ...$extraLogContext,
        ]);

        OrphanedPaymentNotification::dispatchFor(
            $reservation,
            $paymentId,
            context: $context,
        );
    }

    /**
     * True only when the gateway itself reports the intent can no longer take money —
     * cancelPaymentIntent tolerates transient failures silently, so this re-read is the only
     * safe signal. A still-live status or a throwing retrieve returns false so the caller
     * keeps the reference.
     */
    protected function intentIsVerifiablyGone(PaymentInterface $gateway, Reservation $reservation, string $paymentId): bool
    {
        try {
            $intent = $gateway->retrievePaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            return false;
        }

        return $intent === null || ($intent->status ?? '') === 'canceled';
    }

    /**
     * Void any open intent, tolerating gateway failures. Reads ids from the current model
     * state, which callers must have synced to the committed row (via transitionTo()) so a
     * concurrent pay-page write is not missed. No-op when no intent was ever created.
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
     * Void an intent after a terminal transition has committed, tolerating gateway failures:
     * local state is already correct, so errors are only logged (mirrors Reservation::expire()).
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
     * the locked fresh row is the race backstop. A CONFIRMED row with an empty payment_id is an
     * out-of-band confirmation (a webhook confirm always leaves the intent id), so it also skips
     * the provider. Anything else with an empty payment_id (e.g. a PENDING row whose cancelled
     * intent may still carry an orphaned charge) keeps going through the gateway so the failure
     * surfaces to the caller instead of silently marking captured money as refunded.
     */
    protected function refundThroughGateway(Reservation $fresh): void
    {
        if ($fresh->gatewayHoldsNoCharge() || $fresh->confirmedWithoutGatewayCharge()) {
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

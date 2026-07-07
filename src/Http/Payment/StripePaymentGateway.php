<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\CurlClient;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripePaymentGateway implements PaymentInterface
{
    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Credit Card';
    }

    public function paymentView(): string
    {
        return 'statamic-resrv::livewire.checkout-payment';
    }

    /**
     * All Stripe calls go through here so they share short HTTP timeouts. stripe-php defaults to
     * 80s request / 30s connect, but refund() runs inside transitionTo()'s row lock — a Stripe
     * brownout at those defaults would pin the reservation row (and a DB connection) long enough
     * to stall webhooks, expiry sweeps and concurrent status transitions.
     */
    protected function getClient($reservation): StripeClient
    {
        $httpClient = CurlClient::instance();
        $httpClient->setTimeout(15);
        $httpClient->setConnectTimeout(5);
        ApiRequestor::setHttpClient($httpClient);

        return new StripeClient($this->getSecretKey($reservation));
    }

    public function paymentIntent($payment, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        // Inline gateway ignores $returnUrl (declared only to model the convention; see Step 12).
        $stripe = $this->getClient($reservation);
        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $payment->raw(),
            'currency' => Str::lower(config('resrv-config.currency_isoCode')),
            'metadata' => array_merge(['reservation_id' => $reservation->id], $this->filterCustomerData($data)),
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);

        return $paymentIntent;
    }

    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        try {
            return $this->getClient($reservation)->paymentIntents->retrieve($paymentId);
        } catch (InvalidRequestException $e) {
            // Only a definitely-gone intent (deleted / never existed) may be replaced with a
            // fresh one — null tells resolveOrCreateIntent it is safe to mint a replacement.
            // Every transient failure (timeout, 429, 5xx, auth) must propagate instead, so a
            // brownout on this read can't orphan a still-payable intent behind a second one.
            if ($e->getError()?->code === 'resource_missing' || $e->getHttpStatus() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        $stripe = $this->getClient($reservation);

        try {
            $intent = $stripe->paymentIntents->retrieve($paymentId);

            if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture'], true)) {
                $stripe->paymentIntents->cancel($paymentId);
            }
        } catch (ApiErrorException $e) {
            Log::warning('Failed to cancel Stripe payment intent: '.$e->getMessage(), [
                'payment_id' => $paymentId,
                'reservation_id' => $reservation->id,
            ]);
        }
    }

    public function refund($reservation)
    {
        $stripe = $this->getClient($reservation);

        $params = [
            'payment_intent' => $reservation->payment_id,
            'reverse_transfer' => false,
        ];

        // Stable per-(reservation, intent) idempotency key: if a connection drops AFTER
        // Stripe processed the refund (or the status transition rolls back post-refund), a
        // retry within Stripe's idempotency window (~24h) replays the original response
        // instead of issuing a second refund. Past that window the key is forgotten; because
        // these refunds are always for the full intent, Stripe then rejects the retry as
        // already refunded, which reconciles below to the existing refund instead of double-paying.
        $idempotencyKey = 'resrv-refund-'.$reservation->id.'-'.$reservation->payment_id;

        try {
            return $stripe->refunds->create($params, ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $exception) {
            if ($existing = $this->findRefundWhenAlreadyRefunded($stripe, $reservation, $exception)) {
                return $existing;
            }

            // Stripe replays the FIRST response recorded under a key — errors included — for the
            // whole idempotency window. Most replayed errors are definitive failures (4xx), so one
            // retry under a fresh key lets a fixed cause (e.g. a topped-up balance) succeed instead
            // of re-surfacing the stale failure. A replayed 500 is NOT definitive — its side effects
            // are indeterminate — but the fresh-key retry stays money-safe because these refunds are
            // always for the full intent: if a hidden refund exists, Stripe rejects the retry as
            // already refunded and the reconciliation below surfaces that refund as the result.
            if (! $this->isReplayedError($exception)) {
                // Every Stripe failure mode (invalid request, connection, auth, rate limit)
                // must surface as RefundFailedException so callers roll back the status
                // transition and show their refund-failed message instead of a 500.
                throw new RefundFailedException($exception->getMessage());
            }

            try {
                return $stripe->refunds->create($params, ['idempotency_key' => $idempotencyKey.'-'.now()->timestamp]);
            } catch (ApiErrorException $retryException) {
                if ($existing = $this->findRefundWhenAlreadyRefunded($stripe, $reservation, $retryException)) {
                    return $existing;
                }

                throw new RefundFailedException($retryException->getMessage());
            }
        }
    }

    /**
     * When Stripe rejects the refund because the charge is already fully refunded, the money
     * is exactly where the caller wants it — return the existing refund as success instead of
     * throwing (which would roll back the status transition and strand a refunded charge on a
     * CONFIRMED reservation). Covers a first attempt whose replayed 500 actually had side
     * effects, a re-run past the idempotency window, and out-of-band dashboard refunds.
     * Returns null for every other error — and when the lookup itself fails — so the caller's
     * RefundFailedException path runs with the original message.
     */
    protected function findRefundWhenAlreadyRefunded(StripeClient $stripe, Reservation $reservation, ApiErrorException $exception): ?Refund
    {
        if ($exception->getError()?->code !== 'charge_already_refunded') {
            return null;
        }

        try {
            return $stripe->refunds->all([
                'payment_intent' => $reservation->payment_id,
                'limit' => 1,
            ])->data[0] ?? null;
        } catch (ApiErrorException) {
            return null;
        }
    }

    protected function isReplayedError(ApiErrorException $exception): bool
    {
        $headers = $exception->getHttpHeaders();

        return $headers !== null && strtolower((string) ($headers['Idempotent-Replayed'] ?? '')) === 'true';
    }

    protected function filterCustomerData($data)
    {
        // Stripe caps metadata at 50 keys with keys ≤ 40 and values ≤ 500 characters. The customer
        // payload is free-text from the checkout form, so clamp it to those limits — the caller merges
        // reservation_id in separately, so cap at 49 — to avoid an InvalidRequestException on create().
        return collect($data)
            ->filter(fn ($value, $key) => is_string($value) && mb_strlen((string) $key) <= 40)
            ->take(49)
            ->map(fn ($value) => mb_substr($value, 0, 500))
            ->toArray();
    }

    public function getPublicKey($reservation)
    {
        $key = config('resrv-config.stripe_publishable_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $key)) {
            return $key[$handle];
        }
    }

    public function getSecretKey($reservation)
    {
        $key = config('resrv-config.stripe_secret_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $key)) {
            return $key[$handle];
        }
    }

    public function getWebhookSecret($reservation)
    {
        $secret = config('resrv-config.stripe_webhook_secret');
        if (! is_array($secret)) {
            return $secret;
        }

        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $secret)) {
            return $secret[$handle];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $paymentIntent = request()->input('payment_intent');

        $reservation = Reservation::findByPaymentId($paymentIntent)->first();

        // payment_id may have been cleared by Checkout::cancelActiveIntent; return failure
        // so verifyPayment()'s stale-intent path handles reconciliation.
        if (! $reservation) {
            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        $stripe = $this->getClient($reservation);

        $status = $stripe->paymentIntents->retrieve($paymentIntent, []);

        if ($status->status === 'succeeded' || $status->status === 'processing') {
            return [
                'status' => true,
                'reservation' => $reservation->toArray(),
            ];
        }

        return [
            'status' => false,
            'reservation' => $reservation->toArray(),
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function verifyPayment($request)
    {
        $payload = json_decode($request->getContent(), true);

        // Untrusted until constructEvent() verifies the signature below. Used only to locate the
        // reservation, which is needed to resolve a per-collection webhook secret.
        $data = $payload['data']['object'];

        $reservation = Reservation::findByPaymentId($data['id'])->first();

        // Checkout::cancelActiveIntent clears payment_id, so fall back to metadata reservation_id
        // for a racing .succeeded webhook.
        $isStaleIntent = false;
        if (! $reservation && isset($data['metadata']['reservation_id'])) {
            $reservation = Reservation::find($data['metadata']['reservation_id']);
            $isStaleIntent = (bool) $reservation;
        }

        if (! $reservation) {
            Log::info('Reservation not found for id '.$data['id']);

            return response()->json([], 200);
        }

        // Refuse verification when the webhook secret is missing — an empty secret makes the HMAC
        // forgeable by anyone on this CSRF-exempt endpoint.
        $webhookSecret = $this->getWebhookSecret($reservation);

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            Log::error('Stripe webhook secret is not configured; rejecting webhook to prevent signature forgery.', [
                'reservation_id' => $reservation->id,
            ]);

            abort(500);
        }

        $sig_header = $request->header('Stripe-Signature');

        if (empty($sig_header)) {
            abort(403);
        }

        try {
            // Omit $tolerance to keep Stripe's default 300s replay-window check.
            $event = Webhook::constructEvent(
                $request->getContent(),
                $sig_header,
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            abort(403);
        } catch (UnexpectedValueException $e) {
            // Invalid payload
            abort(403);
        }

        // Signature verified: re-read the payload from the trusted event and only now act on
        // reservation state, so an unsigned request can't probe status before verification.
        $data = $event->data->object;

        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return response()->json([], 200);
        }

        if ($event->type === 'payment_intent.succeeded') {
            // Stale intent: the customer moved on and this charge is now orphaned. Notify
            // admins for manual refund; leave the reservation untouched.
            if ($isStaleIntent) {
                Log::warning('Stripe payment intent succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                    'reservation_id' => $reservation->id,
                    'payment_intent_id' => $data['id'],
                    'current_payment_id' => $reservation->payment_id,
                    'current_payment_gateway' => $reservation->payment_gateway,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $data['id'], $event->id ?? null);

                return response()->json([], 200);
            }

            // Terminal reservation: the charge can't attach to a live booking, so notify and stop.
            if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $data['id'], $event->id ?? null)) {
                return response()->json([], 200);
            }

            // Defense-in-depth: refuse to confirm if the charged amount no longer matches what's owed.
            $expectedAmount = $reservation->payment->add($reservation->payment_surcharge)->raw();

            if (isset($data['amount_received']) && (int) $data['amount_received'] !== (int) $expectedAmount) {
                Log::warning('Stripe succeeded webhook amount does not match the reservation total; not confirming.', [
                    'reservation_id' => $reservation->id,
                    'payment_intent_id' => $data['id'],
                    'amount_received' => $data['amount_received'],
                    'expected_amount' => $expectedAmount,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $data['id'], $event->id ?? null);

                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                    'gateway' => $reservation->payment_gateway ?: 'stripe',
                    'payment_id' => $data['id'],
                ]);

                return response()->json([], 200);
            }

            // Lost the confirm race (row expired under the lock): surface any orphaned charge.
            $reservation->refresh();
            OrphanedPaymentNotification::notifyIfOrphaned($reservation, $data['id'], $event->id ?? null);

            return response()->json([], 200);
        }
        if ($event->type === 'payment_intent.payment_failed') {
            // A failed attempt is retryable: keep the reservation PENDING so the customer can retry
            // the same intent. ExpireReservations reclaims the hold if they abandon.
            if ($isStaleIntent) {
                return response()->json([], 200);
            }

            Log::info('Stripe payment_intent.payment_failed; leaving reservation PENDING for retry.', [
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $data['id'],
            ]);

            return response()->json([], 200);
        }
        if ($event->type === 'payment_intent.canceled') {
            // Stale intent cancelled by us — ignore so we don't cascade-cancel an active reservation.
            if ($isStaleIntent) {
                return response()->json([], 200);
            }

            // A dead intent is EXPIRED, not REFUNDED — no money moved. expire() releases the hold.
            if ($reservation->status === ReservationStatus::PENDING->value) {
                $reservation->expire();
            }

            return response()->json([], 200);
        }

        // Acknowledge event types we don't act on with a 200 so Stripe stops retrying; returning a
        // Response (rather than falling through to null) lets WebhookController surface it.
        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}

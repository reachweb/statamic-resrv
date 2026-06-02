<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
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

    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        Stripe::setApiKey($this->getSecretKey($reservation));
        $paymentIntent = PaymentIntent::create([
            'amount' => $payment->raw(),
            'currency' => Str::lower(config('resrv-config.currency_isoCode')),
            'metadata' => array_merge(['reservation_id' => $reservation->id], $this->filterCustomerData($data)),
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);

        return $paymentIntent;
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        Stripe::setApiKey($this->getSecretKey($reservation));

        try {
            $intent = PaymentIntent::retrieve($paymentId);

            if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture'], true)) {
                $intent->cancel();
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
        Stripe::setApiKey($this->getSecretKey($reservation));
        try {
            $attemptRefund = Refund::create([
                'payment_intent' => $reservation->payment_id,
                'reverse_transfer' => false,
            ]);
        } catch (InvalidRequestException $exception) {
            throw new RefundFailedException($exception->getMessage());
        }

        return $attemptRefund;
    }

    protected function filterCustomerData($data)
    {
        $customerData = collect($data);
        $filteredData = $customerData->filter(function ($value, $key) {
            return is_string($value);
        });

        return $filteredData->toArray();
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

        $stripe = new StripeClient($this->getSecretKey($reservation));

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

        Stripe::setApiKey($this->getSecretKey($reservation));

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

                OrphanedPaymentNotification::dispatchFor($reservation, $data['id']);

                return response()->json([], 200);
            }

            // Succeeded webhook for a terminal reservation — charge exists with no live reservation;
            // notify admins for manual refund.
            if (in_array($reservation->status, [
                ReservationStatus::EXPIRED->value,
                ReservationStatus::REFUNDED->value,
                ReservationStatus::PARTNER->value,
            ], true)) {
                Log::warning('Stripe succeeded webhook for a terminal reservation — manual refund likely required.', [
                    'reservation_id' => $reservation->id,
                    'reservation_status' => $reservation->status,
                    'payment_intent_id' => $data['id'],
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $data['id']);

                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED)) {
                ReservationConfirmed::dispatch($reservation);
            }

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
    }

    public function verifyWebhook()
    {
        return true;
    }
}

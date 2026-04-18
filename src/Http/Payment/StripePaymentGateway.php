<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;
use Stripe\Event;
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

        // A missing match means payment_id was cleared by Checkout::cancelActiveIntent, which
        // is exactly the condition verifyPayment() treats as stale and refuses to confirm.
        // Falling back to the session reservation here would show the customer a success page
        // for a reservation the webhook will never mark confirmed — hand off to the failure
        // path so manual reconciliation (via the stale-intent warning log) is the single
        // source of truth.
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

        $data = $payload['data']['object'];

        $reservation = Reservation::findByPaymentId($data['id'])->first();

        // Checkout::cancelActiveIntent clears payment_id before asking Stripe to cancel the
        // intent, so a racing .succeeded webhook can no longer reconcile by payment_id. Fall
        // back to the reservation_id stashed in the intent metadata so the charge isn't lost.
        $isStaleIntent = false;
        if (! $reservation && isset($data['metadata']['reservation_id'])) {
            $reservation = Reservation::find($data['metadata']['reservation_id']);
            $isStaleIntent = (bool) $reservation;
        }

        if (! $reservation) {
            Log::info('Reservation not found for id '.$data['id']);

            return response()->json([], 200);
        }

        if ($reservation->status === ReservationStatus::CONFIRMED) {
            return response()->json([], 200);
        }

        Stripe::setApiKey($this->getSecretKey($reservation));

        try {
            $event = Event::constructFrom(json_decode($request->getContent(), true));
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            abort(403);
        }

        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $sig_header,
                $this->getWebhookSecret($reservation),
                null,
                false
            );
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            abort(403);
        } catch (UnexpectedValueException $e) {
            // Invalid payload
            abort(403);
        }

        if ($event->type === 'payment_intent.succeeded') {
            // A stale intent means the customer moved on (refresh, back, gateway switch, coupon change)
            // before this webhook arrived. The charge exists on Stripe but no longer matches the
            // reservation's current state — confirming it would send emails/decrease inventory
            // against an amount or gateway the reservation is no longer tied to. Log and hand off
            // to manual reconciliation instead.
            if ($isStaleIntent) {
                Log::warning('Stripe payment intent succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                    'reservation_id' => $reservation->id,
                    'payment_intent_id' => $data['id'],
                    'current_payment_id' => $reservation->payment_id,
                    'current_payment_gateway' => $reservation->payment_gateway,
                ]);

                return response()->json([], 200);
            }

            ReservationConfirmed::dispatch($reservation);

            return response()->json([], 200);
        }
        if ($event->type === 'payment_intent.payment_failed' || $event->type === 'payment_intent.canceled') {
            // Stale intents were cancelled deliberately by us (Checkout::cancelActiveIntent);
            // ignore their failure/cancellation webhooks so we don't cascade-cancel a
            // reservation the customer is still using.
            if (! $isStaleIntent) {
                ReservationCancelled::dispatch($reservation);
            }

            return response()->json([], 200);
        }
    }

    public function verifyWebhook()
    {
        return true;
    }
}

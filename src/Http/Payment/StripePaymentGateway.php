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
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripePaymentGateway implements PaymentInterface
{
    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        Stripe::setApiKey($this->getPublicKey($reservation));
        $paymentIntent = PaymentIntent::create([
            'amount' => $payment->raw(),
            'currency' => Str::lower(config('resrv-config.currency_isoCode')),
            'metadata' => array_merge(['reservation_id' => $reservation->id], $this->filterCustomerData($data)),
        ]);

        return $paymentIntent;
    }

    public function refund($reservation)
    {
        Stripe::setApiKey($this->getPublicKey($reservation));
        try {
            $attemptRefund = Refund::create([
                'payment_intent' => $reservation->payment_id,
            ]);
        } catch (\Stripe\Exception\InvalidRequestException $exception) {
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
        $key = config('resrv-config.stripe_secret_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $key)) {
            return $key[$handle];
        }
    }

    public function supportsWebhooks(): bool
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

        $stripe = new StripeClient($this->getPublicKey($reservation));

        $status = $stripe->paymentIntents->retrieve($paymentIntent, []);

        if ($status->status === 'succeeded' || $status->status === 'processing') {
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        return [
            'status' => false,
            'reservation' => $reservation ? $reservation->toArray() : [],
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

        if (! $reservation) {
            Log::info('Reservation not found for id '.$data['id']);

            return response()->json([], 200);
        }

        if ($reservation->status === ReservationStatus::CONFIRMED) {
            return response()->json([], 200);
        }

        Stripe::setApiKey($this->getPublicKey($reservation));

        try {
            $event = Event::constructFrom(json_decode($request->getContent(), true));
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            abort(403);
        }

        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = Webhook::constructEvent(
                $request->getContent(), $sig_header, config('resrv-config.stripe_webhook_secret')
            );
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            abort(403);
        }

        if ($event->type === 'payment_intent.succeeded') {
            ReservationConfirmed::dispatch($reservation);

            return response()->json([], 200);
        }
        if ($event->type === 'payment_intent.payment_failed' || $event->type === 'payment_intent.canceled') {
            ReservationCancelled::dispatch($reservation);

            return response()->json([], 200);
        }
    }

    public function verifyWebhook()
    {
        return true;
    }
}

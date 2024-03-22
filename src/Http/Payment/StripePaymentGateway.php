<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeClient;

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

    public function handleRedirectBack(): bool
    {
        $paymentIntent = request()->input('payment_intent');

        $reservation = Reservation::findByPaymentId($paymentIntent)->first();

        $stripe = new StripeClient($this->getPublicKey($reservation));

        $status = $stripe->paymentIntents->retrieve($paymentIntent, []);

        if ($status->status === 'succeeded' || $status->status === 'processing') {
            // Update the reservation status to webhook if it's still pending
            if ($reservation->status === 'pending') {
                $reservation->update(['status' => 'webhook']);
            }

            return true;
        }

        return false;
    }
}

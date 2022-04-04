<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;

class StripePaymentGateway implements PaymentInterface
{
    public function paymentIntent($payment, $reservation_id, $data)
    {
        Stripe::setApiKey(config('resrv-config.stripe_secret_key'));
        $paymentIntent = PaymentIntent::create([
            'amount' => $payment->raw(),
            'currency' => Str::lower(config('resrv-config.currency_isoCode')),
            'metadata' => array_merge(['reservation_id' => $reservation_id], $this->filterCustomerData($data)),
        ]);

        return $paymentIntent;
    }

    public function refund($payment_id)
    {
        Stripe::setApiKey(config('resrv-config.stripe_secret_key'));
        try {
            $attemptRefund = Refund::create([
                'payment_intent' => $payment_id,
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
}

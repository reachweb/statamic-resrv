<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Illuminate\Support\Str;

class StripePaymentGateway implements PaymentInterface
{
    public function paymentIntent($amount, $reservation_id, $data)
    {
        

        Stripe::setApiKey(config('resrv-config.stripe_key'));
        $paymentIntent = PaymentIntent::create([
            'amount' => $amount * 100,
            'currency' => Str::lower(config('resrv-config.currency_isoCode')),
            'metadata' => array_merge(['reservation_id' => $reservation_id], $this->filterCustomerData($data))
        ]);
        return $paymentIntent;
    }

    protected function filterCustomerData($data) {
        $customerData = collect($data);
        $filteredData = $customerData->filter(function($value, $key) {
            return is_string($value);
        });
        return $filteredData->toArray();
    }

}
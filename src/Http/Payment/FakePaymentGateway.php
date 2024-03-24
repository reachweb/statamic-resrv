<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentInterface
{
    public function paymentIntent($amount, $reservation, $data)
    {
        $data = new \stdClass;
        $data->id = Str::random(28);
        $data->client_secret = Str::random(56);
        $data->reservation = '';
        $data->key = $this->getPublicKey($reservation);

        return $data;
    }

    public function refund($reservation)
    {
        if ($this->getPublicKey($reservation)) {
            return true;
        }
    }

    public function getPublicKey($reservation)
    {
        $key = config('resrv-config.stripe_secret_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (in_array($handle, $key)) {
            return $key[$handle];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function handleRedirectBack(): bool
    {
        return true;
    }

    public function verifyPayment($request): void
    {

    }
}

<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentInterface
{
    public function paymentIntent($amount, $reservation_id, $data)
    {   
        $data = new \StdClass;
        $data->id = Str::random(28);
        $data->client_secret = Str::random(56);
        $data->reservation = '';
        return $data;
    }
    public function refund($payment_id) {
        return true;
    }
}
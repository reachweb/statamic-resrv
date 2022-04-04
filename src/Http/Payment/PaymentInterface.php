<?php

namespace Reach\StatamicResrv\Http\Payment;

interface PaymentInterface
{
    public function paymentIntent($amount, $reservation_id, $data);

    public function refund($payment_id);
}

<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

interface PaymentInterface
{
    public function paymentIntent($amount, Reservation $reservation, $data);

    public function refund(Reservation $reservation);

    public function getPublicKey(Reservation $reservation);

    public function supportsWebhooks(): bool;

    public function handleRedirectBack(): bool;

    public function verifyPayment($request): void;
}

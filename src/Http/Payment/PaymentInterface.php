<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

interface PaymentInterface
{
    public function paymentIntent($amount, Reservation $reservation, $data);

    public function refund(Reservation $reservation);

    public function getPublicKey(Reservation $reservation);

    public function getSecretKey(Reservation $reservation);

    public function getWebhookSecret(Reservation $reservation);

    public function supportsWebhooks(): bool;

    public function redirectsForPayment(): bool;

    public function handleRedirectBack(): array;

    public function handlePaymentPending(): bool|array;

    public function verifyPayment($request);

    public function verifyWebhook();
}

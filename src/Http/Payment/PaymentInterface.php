<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

interface PaymentInterface
{
    public function name(): string;

    public function label(): string;

    public function paymentView(): string;

    public function paymentIntent($amount, Reservation $reservation, $data);

    /**
     * Fetch a previously created payment intent so an interrupted payment can resume
     * without creating (and potentially double-charging) a second intent. Return null
     * when the intent cannot be retrieved — callers then create a fresh one.
     */
    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object;

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void;

    public function refund(Reservation $reservation);

    public function getPublicKey(Reservation $reservation);

    public function getSecretKey(Reservation $reservation);

    public function getWebhookSecret(Reservation $reservation);

    public function supportsWebhooks(): bool;

    public function supportsManualConfirmation(): bool;

    /**
     * Whether refund() actually returns money through the gateway's API. Gateways that
     * collect payment out of band (e.g. bank transfer) must return false so automated
     * flows never mark a reservation refunded without money moving.
     */
    public function supportsAutomaticRefunds(): bool;

    public function redirectsForPayment(): bool;

    public function handleRedirectBack(): array;

    public function handlePaymentPending(): bool|array;

    public function verifyPayment($request);

    public function verifyWebhook();
}

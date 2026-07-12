<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

interface PaymentInterface
{
    public function name(): string;

    public function label(): string;

    public function paymentView(): string;

    /**
     * Create a provider payment for $amount. Redirect gateways must build their return/success
     * URL from `$returnUrl` when given — it may already carry a query string (the pay-by-link
     * page's `?ref=…&hash=…`), so append return parameters with a separator-aware join — and
     * fall back to the checkout-complete entry when null. Inline gateways may ignore it (Resrv
     * sets their return leg separately). See UPGRADE-PAYMENT-GATEWAYS.md Step 12.
     */
    public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null);

    /**
     * Fetch a previously created payment intent so an interrupted payment can resume
     * without creating (and potentially double-charging) a second intent. Return an
     * object exposing `->status`. Return null ONLY when the intent is definitively
     * gone (deleted / never existed) — null tells callers it is safe to mint a
     * replacement. On any transient failure (timeout, 429, 5xx, auth, connection)
     * THROW instead: returning null on a brownout would let Resrv replace a
     * still-live intent with a second chargeable one. See UPGRADE-PAYMENT-GATEWAYS.md
     * Step 13.
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

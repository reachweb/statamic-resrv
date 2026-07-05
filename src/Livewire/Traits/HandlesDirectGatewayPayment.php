<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Livewire\Attributes\Locked;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;

/**
 * Mounts a gateway payment for a reservation OUTSIDE the checkout session — used by the
 * manual-reservation pay page (and intended for the status-page balance section from plan
 * 008): resolve the reservation's locked gateway, create or resume a payment intent, and
 * expose the properties the gateway's paymentView() blade reads ($wire.clientSecret,
 * $wire.publicKey, $wire.checkoutCompletedUrl — the return URL — and $amount).
 */
trait HandlesDirectGatewayPayment
{
    #[Locked]
    public string $clientSecret = '';

    #[Locked]
    public string $publicKey = '';

    #[Locked]
    public float $amount = 0;

    #[Locked]
    public string $paymentView = '';

    /** The absolute URL the gateway sends the customer back to after paying. */
    #[Locked]
    public string $checkoutCompletedUrl = '';

    /** Set when the existing intent already succeeded or is processing — nothing to remount. */
    #[Locked]
    public bool $paymentProcessing = false;

    /**
     * Create-or-resume an intent for $amount and mount the gateway's payment view.
     * Returns a redirect response for redirect gateways, null otherwise.
     *
     * @throws UnknownPaymentGateway
     */
    protected function mountGatewayPayment(Reservation $reservation, PriceClass $amount, string $returnUrl)
    {
        $gateway = app(PaymentGatewayManager::class)->forReservation($reservation);

        $intent = $this->resolveOrCreateIntent($gateway, $reservation, $amount);

        if ($intent === null) {
            $this->paymentProcessing = true;

            return null;
        }

        if ($gateway->redirectsForPayment()) {
            $redirectUrl = $intent->redirectTo;
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';

            return redirect()->away($redirectUrl.$separator.http_build_query([
                'resrv_gateway' => $reservation->payment_gateway,
            ]));
        }

        $this->publicKey = (string) $gateway->getPublicKey($reservation);
        $this->paymentView = $gateway->paymentView();
        $this->checkoutCompletedUrl = $returnUrl;
        $this->amount = (float) $amount->format();
        $this->clientSecret = (string) $intent->client_secret;

        return null;
    }

    /**
     * Resume the reservation's stored intent when it is still payable; a dead (cancelled)
     * intent gets replaced, and a succeeded/processing one returns null — the money is
     * already moving, so creating another intent would risk a double charge.
     */
    protected function resolveOrCreateIntent(PaymentInterface $gateway, Reservation $reservation, PriceClass $amount): ?object
    {
        if (is_string($reservation->payment_id) && $reservation->payment_id !== '') {
            $existing = $gateway->retrievePaymentIntent($reservation->payment_id, $reservation);

            if ($existing !== null && in_array($existing->status ?? '', ['succeeded', 'processing'], true)) {
                return null;
            }

            if ($existing !== null && ($existing->status ?? '') !== 'canceled') {
                return $existing;
            }
        }

        $intent = $gateway->paymentIntent($amount, $reservation, $reservation->customerData ?? collect());

        $reservation->update(['payment_id' => $intent->id]);

        return $intent;
    }

    /**
     * What the gateway's return redirect says about the payment, from the current request
     * query: 'succeeded' | 'processing' | 'failed' | null (no return parameters present).
     * The webhook remains the source of truth — this only drives interim messaging.
     */
    protected function gatewayReturnStatus(): ?string
    {
        $redirectStatus = request()->query('redirect_status');

        if (is_string($redirectStatus) && $redirectStatus !== '') {
            return $redirectStatus;
        }

        if (request()->query('payment_intent') || request()->query('payment_intent_client_secret')) {
            return 'processing';
        }

        return null;
    }
}

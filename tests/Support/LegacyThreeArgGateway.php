<?php

namespace Reach\StatamicResrv\Tests\Support;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;

/**
 * A gateway whose paymentIntent() declares STRICTLY three parameters — models an addon predating
 * the optional 4th $returnUrl arg. Guards the backward-compat contract: the interface stays at 3
 * params (a 4th would fatal this class at boot), and a 4th positional arg from core is ignored.
 */
class LegacyThreeArgGateway implements PaymentInterface
{
    /** Set true when paymentIntent() runs. */
    public bool $created = false;

    public function name(): string
    {
        return 'legacy3';
    }

    public function label(): string
    {
        return 'Legacy Three Arg';
    }

    public function paymentView(): string
    {
        return 'statamic-resrv::livewire.checkout-payment';
    }

    public function paymentIntent($amount, Reservation $reservation, $data)
    {
        $this->created = true;

        $intent = new \stdClass;
        $intent->id = 'legacy_'.Str::random(24);
        $intent->client_secret = 'legacy_cs_'.Str::random(24);

        return $intent;
    }

    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        return null;
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        //
    }

    public function refund($reservation)
    {
        return true;
    }

    public function getPublicKey($reservation)
    {
        return '';
    }

    public function getSecretKey($reservation)
    {
        return '';
    }

    public function getWebhookSecret($reservation)
    {
        return '';
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function handleRedirectBack(): array
    {
        return ['status' => false];
    }

    public function handlePaymentPending(): bool|array
    {
        return false;
    }

    public function verifyPayment($request)
    {
        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Support;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Redirect-style gateway double (redirectsForPayment() === true). paymentIntent() records the 4th
 * $returnUrl it was handed so tests can assert the return-URL base; handleRedirectBack() reads a
 * `status` query param to drive the return-page interim state.
 */
class FakeRedirectGateway extends FakePaymentGateway
{
    /** The return-URL base handed to the most recent paymentIntent() call. */
    public ?string $lastReturnUrl = null;

    /**
     * When false, retrievePaymentIntent() returns the Step-13 minimum contract — an object
     * exposing only ->status — modelling a spec-compliant third-party redirect gateway whose
     * resumed intents carry no provider URL.
     */
    public bool $retrieveIncludesRedirectTo = true;

    public function name(): string
    {
        return 'fakeredirect';
    }

    public function label(): string
    {
        return 'Fake Redirect';
    }

    public function redirectsForPayment(): bool
    {
        return true;
    }

    public function paymentIntent($amount, $reservation, $data, ?string $returnUrl = null)
    {
        $this->lastReturnUrl = $returnUrl;

        $intent = new \stdClass;
        $intent->id = 'redir_'.Str::random(24);
        $intent->client_secret = 'redir_cs_'.Str::random(24);
        $intent->redirectTo = 'https://provider.test/checkout/'.$intent->id;

        $this->createdIntents[] = [
            'payment_id' => $intent->id,
            'reservation_id' => $reservation->id ?? null,
        ];

        return $intent;
    }

    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        $intent = new \stdClass;
        $intent->status = 'requires_payment_method';

        if ($this->retrieveIncludesRedirectTo) {
            $intent->id = $paymentId;
            $intent->client_secret = 'redir_cs_'.$paymentId;
            $intent->redirectTo = 'https://provider.test/checkout/'.$paymentId;
        }

        return $intent;
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        return match (request()->input('status')) {
            'success' => ['status' => true],
            'pending' => ['status' => 'pending'],
            default => ['status' => false],
        };
    }
}

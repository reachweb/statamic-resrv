<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Reservation;

class OfflinePaymentGateway implements PaymentInterface
{
    public function name(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return 'Pay Later / Bank Transfer';
    }

    public function paymentView(): string
    {
        return 'statamic-resrv::livewire.checkout-payment-offline';
    }

    public function paymentIntent($amount, Reservation $reservation, $data)
    {
        $intent = new \stdClass;
        $intent->id = 'offline_'.Str::random(24);
        $intent->client_secret = 'offline_'.Str::random(48);

        return $intent;
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
        return false;
    }

    public function supportsManualConfirmation(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        return ['status' => false];
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
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

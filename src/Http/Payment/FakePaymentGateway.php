<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;

class FakePaymentGateway implements PaymentInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Payment';
    }

    public function paymentView(): string
    {
        return 'statamic-resrv::livewire.checkout-payment';
    }

    public function paymentIntent($amount, $reservation, $data)
    {
        $data = new \stdClass;
        $data->id = Str::random(28);
        $data->client_secret = Str::random(56);
        $data->reservation = '';
        $data->key = $this->getPublicKey($reservation);

        return $data;
    }

    public function refund($reservation)
    {
        if ($this->getPublicKey($reservation)) {
            return true;
        }
    }

    public function getPublicKey($reservation)
    {
        $key = config('resrv-config.stripe_publishable_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $key)) {
            return $key[$handle];
        }
    }

    public function getSecretKey($reservation)
    {
        $key = config('resrv-config.stripe_secret_key');
        if (! is_array($key)) {
            return $key;
        }
        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $key)) {
            return $key[$handle];
        }
    }

    public function getWebhookSecret($reservation)
    {
        $secret = config('resrv-config.stripe_webhook_secret');
        if (! is_array($secret)) {
            return $secret;
        }

        $handle = $reservation->entry()->collection->handle();
        if (array_key_exists($handle, $secret)) {
            return $secret[$handle];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
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

        $status = request()->input('status');

        if ($status === 'success') {
            return [
                'status' => true,
            ];
        }

        return [
            'status' => false,
        ];
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
        $reservation = Reservation::findOrFail($request->get('reservation_id'));

        if ($request->get('status') === 'success') {
            ReservationConfirmed::dispatch($reservation);

            return response()->json([], 200);
        }
        if ($request->get('status') === 'fail') {
            ReservationCancelled::dispatch($reservation);

            return response()->json([], 200);
        }
    }

    public function verifyWebhook()
    {
        return true;
    }
}

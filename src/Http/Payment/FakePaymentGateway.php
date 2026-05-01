<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

class FakePaymentGateway implements PaymentInterface
{
    /**
     * In-memory log of every intent id this instance was asked to cancel. Tests assert on
     * this to verify the real Stripe gateway's cancelPaymentIntent would have been called —
     * the no-op body on the original fake hid cancellation bugs.
     *
     * @var array<int, array{payment_id: string, reservation_id: int|string|null}>
     */
    public array $cancelledIntents = [];

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

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        $this->cancelledIntents[] = [
            'payment_id' => $paymentId,
            'reservation_id' => $reservation->id,
        ];
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

        // Mirror StripePaymentGateway: already-confirmed reservations are an idempotent no-op.
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            return response()->json([], 200);
        }

        if ($request->get('status') === 'success') {
            // Terminal-state reservations (expired/refunded/partner) receiving a success
            // webhook represent orphan payments — manual reconciliation required.
            if (in_array($reservation->status, [
                ReservationStatus::EXPIRED->value,
                ReservationStatus::REFUNDED->value,
                ReservationStatus::PARTNER->value,
            ], true)) {
                Log::warning('Fake gateway success webhook for a terminal reservation — would require manual refund on a real gateway.', [
                    'reservation_id' => $reservation->id,
                    'reservation_status' => $reservation->status,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, (string) ($reservation->payment_id ?: 'fake_intent'));

                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED)) {
                ReservationConfirmed::dispatch($reservation);
            }

            return response()->json([], 200);
        }
        if ($request->get('status') === 'fail') {
            if ($reservation->status !== ReservationStatus::PENDING->value) {
                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::REFUNDED)) {
                ReservationCancelled::dispatch($reservation);
            }

            return response()->json([], 200);
        }
    }

    public function verifyWebhook()
    {
        return true;
    }
}

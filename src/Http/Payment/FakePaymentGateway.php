<?php

namespace Reach\StatamicResrv\Http\Payment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
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

    /**
     * In-memory log of every intent this instance created. Tests assert on this to
     * verify resume-payment flows reuse an existing intent instead of creating (and
     * potentially double-charging) a second one.
     *
     * @var array<int, array{payment_id: string, reservation_id: int|string|null}>
     */
    public array $createdIntents = [];

    /**
     * In-memory log of every reservation this instance was asked to refund. Tests assert on this
     * to verify a no-gateway-charge refund (e.g. an out-of-band confirmation) skips the provider.
     *
     * @var array<int, int|string|null>
     */
    public array $refundCalls = [];

    /**
     * Status that retrievePaymentIntent() reports, so tests can simulate an intent that already
     * captured (e.g. 'succeeded') and assert the out-of-band settlement keeps its charge reference.
     */
    public ?string $retrievedIntentStatus = null;

    /**
     * Intent ids a successful cancel has moved to 'canceled' — a subsequent retrieve reports that,
     * modelling a real gateway (Stripe leaves a cancelled intent in the 'canceled' state). Lets a
     * caller that re-reads after cancelling verify the intent is genuinely dead.
     *
     * @var array<int, string>
     */
    public array $canceledIds = [];

    /**
     * When false, cancelPaymentIntent() records the call but does NOT move the intent to 'canceled' —
     * modelling a transient provider failure that StripePaymentGateway swallows and never surfaces,
     * leaving the intent live. Lets tests exercise the keep-the-reference-on-failed-cancel path.
     */
    public bool $cancelSucceeds = true;

    /**
     * Optional callback fired INSIDE paymentIntent() (after the intent is built), receiving the
     * reservation — lets a test simulate a concurrent state change (a CP cancel/confirm or the
     * hold-lapse sweep) landing during the gateway round-trip, to exercise the locked payability
     * re-check in HandlesDirectGatewayPayment::resolveOrCreateIntent().
     *
     * @var null|callable(Reservation): void
     */
    public $onPaymentIntent = null;

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

    public function paymentIntent($amount, $reservation, $data, ?string $returnUrl = null)
    {
        // Inline gateway ignores $returnUrl (declared only to model the convention; see Step 12).
        $data = new \stdClass;
        $data->id = Str::random(28);
        $data->client_secret = Str::random(56);
        $data->reservation = '';
        $data->key = $this->getPublicKey($reservation);

        $this->createdIntents[] = [
            'payment_id' => $data->id,
            'reservation_id' => $reservation->id ?? null,
        ];

        // Simulate a concurrent transition committing during the (real: network) round-trip.
        if ($this->onPaymentIntent !== null) {
            ($this->onPaymentIntent)($reservation);
        }

        return $data;
    }

    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        $data = new \stdClass;
        $data->id = $paymentId;
        $data->client_secret = 'cs_'.$paymentId;
        $data->status = in_array($paymentId, $this->canceledIds, true)
            ? 'canceled'
            : ($this->retrievedIntentStatus ?? 'requires_payment_method');

        return $data;
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        $this->cancelledIntents[] = [
            'payment_id' => $paymentId,
            'reservation_id' => $reservation->id,
        ];

        // A successful cancel leaves the intent 'canceled' at the provider; a swallowed failure
        // (cancelSucceeds=false) leaves it live, exactly as a real gateway would.
        if ($this->cancelSucceeds) {
            $this->canceledIds[] = $paymentId;
        }
    }

    public function refund($reservation)
    {
        $this->refundCalls[] = $reservation->id;

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
            $paymentId = (string) ($reservation->payment_id ?: 'fake_intent');

            // Terminal reservation: the charge can't attach to a live booking, so notify and stop.
            if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $paymentId)) {
                return response()->json([], 200);
            }

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_WEBHOOK, [
                    'gateway' => $reservation->payment_gateway ?: 'fake',
                    'payment_id' => $paymentId,
                ]);

                return response()->json([], 200);
            }

            // Lost the confirm race (row expired under the lock): surface any orphaned charge.
            $reservation->refresh();
            OrphanedPaymentNotification::notifyIfOrphaned($reservation, $paymentId);

            return response()->json([], 200);
        }
        if ($request->get('status') === 'fail') {
            // Mirror StripePaymentGateway: a failed attempt is retryable, so leave it PENDING.
            Log::info('Fake gateway payment failure; leaving reservation PENDING for retry.', [
                'reservation_id' => $reservation->id,
            ]);

            return response()->json([], 200);
        }

        // Mirror StripePaymentGateway: always return a Response (never fall through to null).
        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}

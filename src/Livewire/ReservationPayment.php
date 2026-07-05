<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\Traits\HandlesDirectGatewayPayment;
use Reach\StatamicResrv\Models\Reservation;

/**
 * The pay-by-link page for admin-created (manual) reservations. URI-only authentication
 * (?ref=&hash= — the HMAC from Reservation::customerLookupHash()), mirroring the
 * reservation-status page's posture: rate-limited, neutral failure messaging, and the
 * webhook stays the confirmation truth — this page only collects the payment.
 */
class ReservationPayment extends Component
{
    use HandlesDirectGatewayPayment;

    #[Locked]
    public ?int $reservationId = null;

    public bool $linkFailed = false;

    public bool $paymentError = false;

    public function mount(): void
    {
        $this->loadReservationFromUri();
    }

    /**
     * Deep-link only: no email-lookup form exists on this page, so a missing or invalid
     * link renders a single neutral notice. Shares the two-bucket rate-limit pattern with
     * the status page but under its own keys, so attempts there don't drain budgets here.
     */
    protected function loadReservationFromUri(): void
    {
        $reference = request()->query('ref');
        $hash = request()->query('hash');

        if (! is_string($reference) || $reference === '' || ! is_string($hash) || $hash === '') {
            $this->linkFailed = true;

            return;
        }

        if (strlen($hash) !== 64 || $this->tooManyLookupAttempts($reference)) {
            $this->linkFailed = true;

            return;
        }

        $reservation = Reservation::findForCustomerLookup($reference, $hash, $this->visibleStatuses());

        if ($reservation === null) {
            $this->recordFailedLookup($reference);
            $this->linkFailed = true;

            return;
        }

        $this->reservationId = $reservation->id;
    }

    protected function tooManyLookupAttempts(string $reference): bool
    {
        return RateLimiter::tooManyAttempts($this->rateLimiterKey($reference), 10)
            || RateLimiter::tooManyAttempts($this->ipRateLimiterKey(), 30);
    }

    protected function recordFailedLookup(string $reference): void
    {
        RateLimiter::hit($this->rateLimiterKey($reference), 600);
        RateLimiter::hit($this->ipRateLimiterKey(), 600);
    }

    protected function rateLimiterKey(string $reference): string
    {
        return 'resrv-payment-lookup:'.sha1((string) request()->ip().'|'.strtoupper(trim($reference)));
    }

    protected function ipRateLimiterKey(): string
    {
        return 'resrv-payment-lookup-ip:'.sha1((string) request()->ip());
    }

    /**
     * Statuses the link resolves for: awaiting payment (the page's purpose) plus every
     * post-decision state, so a paid or cancelled reservation's link explains itself
     * instead of claiming the reservation doesn't exist.
     */
    protected function visibleStatuses(): array
    {
        return [
            ReservationStatusEnum::AWAITING_PAYMENT->value,
            ...ReservationStatusEnum::live(),
            ReservationStatusEnum::REFUNDED->value,
            ReservationStatusEnum::CANCELLED->value,
            ReservationStatusEnum::COMPLETED->value,
        ];
    }

    #[Computed]
    public function reservation(): ?Reservation
    {
        if (! $this->reservationId) {
            return null;
        }

        return Reservation::query()
            ->with(['customer', 'rate', 'extras', 'options.values' => fn ($query) => $query->withTrashed(), 'childs.rate'])
            ->find($this->reservationId);
    }

    /**
     * The single state the blade renders, derived server-side on every request:
     * awaiting | instructions (offline gateway) | deadline_passed | processing |
     * paid | unavailable.
     */
    #[Computed]
    public function state(): string
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return 'unavailable';
        }

        if (in_array($reservation->status, ReservationStatusEnum::live(), true)) {
            return 'paid';
        }

        if ($reservation->status !== ReservationStatusEnum::AWAITING_PAYMENT->value) {
            return 'unavailable';
        }

        if ($this->deadlinePassed($reservation)) {
            return 'deadline_passed';
        }

        if ($this->paymentProcessing || $this->gatewayReturnStatus() === 'processing' || $this->gatewayReturnStatus() === 'succeeded') {
            return 'processing';
        }

        if ($this->gatewayIsOffline($reservation)) {
            return 'instructions';
        }

        return 'awaiting';
    }

    /**
     * The lazy guard: the hold-lapse command may not have run yet, so a past-deadline
     * link must refuse payment on its own.
     */
    protected function deadlinePassed(Reservation $reservation): bool
    {
        return $reservation->hold_expires_at !== null && $reservation->hold_expires_at->isPast();
    }

    protected function gatewayIsOffline(Reservation $reservation): bool
    {
        try {
            return $reservation->resolvePaymentGateway()->supportsManualConfirmation();
        } catch (UnknownPaymentGateway) {
            return false;
        }
    }

    /**
     * Create-or-resume the intent for the STORED amount (payment + payment_surcharge —
     * exactly what the webhook's amount guard compares against) and mount the gateway's
     * payment view. Every precondition re-validates on each call: the button being
     * hidden does not protect the action.
     */
    public function pay()
    {
        $this->paymentError = false;

        $reservation = $this->reservation;

        if (! $reservation
            || $reservation->status !== ReservationStatusEnum::AWAITING_PAYMENT->value
            || $this->deadlinePassed($reservation)
            || $this->gatewayIsOffline($reservation)) {
            return;
        }

        $returnUrl = $reservation->customerPaymentUrl();

        if ($returnUrl === null) {
            $this->paymentError = true;

            return;
        }

        // Fresh Price: add() mutates in place and Eloquent caches cast instances, so
        // adding on the model's own instance would compound across accesses.
        $amount = Price::create($reservation->payment->format())
            ->add($reservation->payment_surcharge);

        try {
            return $this->mountGatewayPayment($reservation->fresh(), $amount, $returnUrl);
        } catch (UnknownPaymentGateway $e) {
            Log::error('The payment gateway recorded for this reservation is no longer configured.', [
                'reservation_id' => $reservation->id,
                'payment_gateway' => $reservation->payment_gateway,
            ]);
            $this->paymentError = true;
        } catch (\Throwable $e) {
            report($e);
            $this->paymentError = true;
        }
    }

    #[Computed]
    public function amountDue(): string
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return '';
        }

        return Price::create($reservation->payment->format())
            ->add($reservation->payment_surcharge)
            ->format();
    }

    public function render()
    {
        if ($this->paymentView) {
            return view($this->paymentView);
        }

        return view('statamic-resrv::livewire.reservation-payment');
    }
}

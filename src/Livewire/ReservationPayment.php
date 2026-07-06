<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Livewire\Traits\HandlesCustomerLookup;
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
    use HandlesCustomerLookup, HandlesDirectGatewayPayment;

    public bool $paymentError = false;

    public function mount(): void
    {
        $this->loadReservationFromUri();
    }

    protected function lookupRateLimiterPrefix(): string
    {
        return 'resrv-payment-lookup';
    }

    /**
     * Deep-link only: no email-lookup form exists on this page, so a missing link renders
     * the same single neutral notice an invalid one does.
     */
    protected function handleMissingLookupParams(): void
    {
        $this->linkFailed = true;
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

        if (! $reservation->isAwaitingPayment()) {
            return 'unavailable';
        }

        if ($this->deadlinePassed($reservation)) {
            return 'deadline_passed';
        }

        if ($this->paymentProcessing || $this->gatewayReturnStatus() === 'processing' || $this->gatewayReturnStatus() === 'succeeded') {
            return 'processing';
        }

        if ($reservation->paymentGatewaySupportsManualConfirmation()) {
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
            || ! $reservation->isAwaitingPayment()
            || $this->deadlinePassed($reservation)
            || $reservation->paymentGatewaySupportsManualConfirmation()) {
            return;
        }

        $returnUrl = $reservation->customerPaymentUrl();

        if ($returnUrl === null) {
            $this->paymentError = true;

            return;
        }

        try {
            return $this->mountGatewayPayment($reservation, $reservation->amountDue(), $returnUrl);
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
        return $this->reservation?->amountDue()->format() ?? '';
    }

    public function render()
    {
        if ($this->paymentView) {
            return view($this->paymentView);
        }

        return view('statamic-resrv::livewire.reservation-payment');
    }
}

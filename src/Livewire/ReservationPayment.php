<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Livewire\Traits\HandlesCustomerLookup;
use Reach\StatamicResrv\Livewire\Traits\HandlesDirectGatewayPayment;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Pay-by-link page for admin-created (manual) reservations. URI-only auth (?ref=&hash=,
 * HMAC from customerLookupHash()), same posture as the status page: rate-limited, neutral
 * failures. The webhook stays the confirmation truth — this page only collects the payment.
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

    /** Deep-link only: no lookup form, so a missing link renders the same neutral notice as an invalid one. */
    protected function handleMissingLookupParams(): void
    {
        $this->linkFailed = true;
    }

    /**
     * Awaiting payment plus every post-decision state, so a paid or cancelled reservation's
     * link explains itself instead of claiming the reservation doesn't exist.
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
     * The single state the blade renders, derived server-side per request: awaiting |
     * instructions (offline gateway) | deadline_passed | processing | paid | unavailable.
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

        $returnStatus = $this->gatewayReturnStatus() ?? $this->redirectGatewayReturnStatus($reservation);

        if ($this->paymentProcessing || $returnStatus === 'processing' || $returnStatus === 'succeeded') {
            return 'processing';
        }

        if ($reservation->paymentGatewaySupportsManualConfirmation()) {
            return 'instructions';
        }

        return 'awaiting';
    }

    /**
     * Interim status for a redirect-gateway return, guarded on the resrv_gateway marker so a plain
     * page load never calls the provider. Reads handleRedirectBack() (never confirms); the webhook
     * stays the truth and a 'failed' return falls through to 'awaiting' for retry.
     */
    protected function redirectGatewayReturnStatus(Reservation $reservation): ?string
    {
        if (! request()->has('resrv_gateway')) {
            return null;
        }

        try {
            $gateway = $reservation->resolvePaymentGateway();

            if (! $gateway->redirectsForPayment()) {
                return null;
            }

            $status = $gateway->handleRedirectBack()['status'] ?? null;
        } catch (\Throwable $e) {
            // Report but don't 500 the customer-facing page on a provider hiccup. Mirrors pay().
            report($e);

            return null;
        }

        return match ($status) {
            true, 'pending' => 'processing',
            false => 'failed',
            default => null,
        };
    }

    /**
     * Lazy guard: the lapse sweep may not have run yet, so a past-deadline link refuses NEW
     * payment attempts itself. An already-mounted intent deliberately stays completable until
     * the sweep voids it — the AWAITING_PAYMENT row still holds stock, so a buzzer-beating
     * payment confirms a held booking rather than orphaning the charge.
     */
    protected function deadlinePassed(Reservation $reservation): bool
    {
        return $reservation->holdDeadlinePassed();
    }

    /**
     * Create-or-resume the intent for the STORED amount (payment + payment_surcharge — what the
     * webhook's amount guard checks) and mount the gateway view. Every precondition re-validates
     * per call: a hidden button does not protect the action.
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
            return $this->mountGatewayPayment(
                $reservation,
                $reservation->amountDue(),
                $returnUrl,
                fn (Reservation $fresh) => $fresh->isAwaitingPayment() && ! $this->deadlinePassed($fresh),
            );
        } catch (ReservationNoLongerPayable $e) {
            // The row was transitioned mid-flight; the minted intent is already voided. Bust the
            // cached computed (it may hold the pre-transition row) so this render shows the new
            // state instead of a payment form. No error notice.
            unset($this->reservation);

            return;
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

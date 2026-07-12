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
     * Interim status for a customer returning from a redirect gateway (e.g. Mollie), guarded on the
     * resrv_gateway return marker so a plain page load never calls the provider. Reads the gateway's
     * handleRedirectBack() (never confirms) and maps to 'processing' | 'failed'; the webhook stays
     * the confirmation truth and a 'failed' return falls through to 'awaiting' for retry.
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
     * The lazy guard: the hold-lapse command may not have run yet, so a past-deadline
     * link must refuse payment on its own.
     *
     * This deliberately guards only NEW payment attempts. An intent mounted before the
     * deadline stays completable until the sweep voids it: while the row is still
     * AWAITING_PAYMENT its stock is still decremented, so a payment landing in the
     * deadline-to-sweep window confirms a booking whose inventory is still held —
     * strictly better than taking the money and orphaning the charge (a webhook after
     * the sweep already stays CANCELLED and notifies the orphan). Sites wanting a
     * tighter window schedule resrv:cancel-lapsed-holds more frequently.
     */
    protected function deadlinePassed(Reservation $reservation): bool
    {
        return $reservation->holdDeadlinePassed();
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
            return $this->mountGatewayPayment(
                $reservation,
                $reservation->amountDue(),
                $returnUrl,
                fn (Reservation $fresh) => $fresh->isAwaitingPayment() && ! $this->deadlinePassed($fresh),
            );
        } catch (ReservationNoLongerPayable $e) {
            // The hold lapsed, or an admin cancelled/confirmed the reservation between the outer guard
            // and the locked intent write; the freshly-minted intent has already been voided. Bust the
            // cached computed — when the transition landed in another process during a gateway round
            // trip it still holds the pre-transition row — so this render shows the reservation's new
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

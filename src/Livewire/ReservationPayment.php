<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
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
     * instructions (offline gateway) | processing | deadline_passed | arrival_passed | paid |
     * unavailable. Money in flight wins over a closed window — the customer has just paid, and
     * the webhook still confirms a row that is awaiting payment — but past the window only the
     * gateway can say so: a return URL's query is client-controlled and outlives its payment.
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

        if ($this->paymentProcessing) {
            return 'processing';
        }

        if ($this->paymentWindowClosed($reservation)) {
            if ($this->returnedFromGateway() && $this->storedIntentIsMovingMoney($reservation)) {
                return 'processing';
            }

            return $reservation->holdDeadlinePassed() ? 'deadline_passed' : 'arrival_passed';
        }

        $returnStatus = $this->gatewayReturnStatus() ?? $this->redirectGatewayReturnStatus($reservation);

        if ($returnStatus === 'processing' || $returnStatus === 'succeeded') {
            return 'processing';
        }

        if ($reservation->paymentGatewaySupportsManualConfirmation()) {
            return 'instructions';
        }

        return 'awaiting';
    }

    #[Computed]
    public function heading(): string
    {
        return trans('statamic-resrv::frontend.'.match ($this->state) {
            'instructions' => 'paymentHeadingInstructions',
            'processing' => 'paymentHeadingProcessing',
            'paid' => 'paymentHeadingConfirmed',
            'deadline_passed', 'arrival_passed' => 'paymentHeadingExpired',
            'unavailable' => 'paymentHeadingUnavailable',
            default => 'completeYourPayment',
        });
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

    protected function returnedFromGateway(): bool
    {
        return $this->gatewayReturnStatus() !== null || request()->has('resrv_gateway');
    }

    /**
     * Whether the gateway reports the stored intent captured or capturing money. A failed read
     * reports false: the closed window shows, and the webhook still confirms a real payment.
     */
    protected function storedIntentIsMovingMoney(Reservation $reservation): bool
    {
        if (! $reservation->hasGatewayPayment()) {
            return false;
        }

        try {
            $intent = $reservation->resolvePaymentGateway()->retrievePaymentIntent((string) $reservation->payment_id, $reservation);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return in_array($intent?->status ?? '', ['succeeded', 'processing', 'requires_capture'], true);
    }

    /**
     * Lazy guard: the lapse sweep may not have run yet, and nothing sweeps a passed arrival day,
     * so the page itself refuses NEW payment attempts once the window closes. An already-mounted
     * intent deliberately stays completable — the AWAITING_PAYMENT row still holds stock, so a
     * buzzer-beating payment confirms a held booking rather than orphaning the charge.
     */
    protected function paymentWindowClosed(Reservation $reservation): bool
    {
        return $reservation->paymentWindowClosed();
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
            || $this->paymentWindowClosed($reservation)
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
                fn (Reservation $fresh) => $fresh->isAwaitingPayment() && ! $this->paymentWindowClosed($fresh),
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

    /** What stays unpaid after this payment (total − requested amount), or null when nothing does. */
    #[Computed]
    public function balanceDue(): ?string
    {
        $reservation = $this->reservation;

        if (! $reservation || ! $reservation->payment->lessThan($reservation->total)) {
            return null;
        }

        return Price::create($reservation->total->format())->subtract($reservation->payment)->format();
    }

    /**
     * The "pay by" line for the effective cut-off (paymentDueAt(): the hold deadline, or the end
     * of the arrival day when that comes first, which the line then names), or null without a hold.
     */
    #[Computed]
    public function paymentDueLine(): ?string
    {
        $reservation = $this->reservation;
        $due = $reservation?->paymentDueAt();

        if ($due === null) {
            return null;
        }

        $arrivalIsCutOff = $reservation->hold_expires_at !== null && $due->lt($reservation->hold_expires_at);

        return trans(
            'statamic-resrv::frontend.'.($arrivalIsCutOff ? 'paymentDueByArrival' : 'paymentDueBy'),
            ['date' => $due->format('d-m-Y H:i T')]
        );
    }

    /** The configured label of the reservation's gateway (blank falls back to the default), or null. */
    #[Computed]
    public function gatewayLabel(): ?string
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return null;
        }

        $manager = app(PaymentGatewayManager::class);
        $name = blank($reservation->payment_gateway) ? $manager->defaultName() : $reservation->payment_gateway;

        if (! is_string($name) || ! $manager->has($name)) {
            return null;
        }

        $label = trim($manager->label($name));

        return $label !== '' ? $label : null;
    }

    /** The site's offline payment instructions setting, or null when unset. */
    #[Computed]
    public function offlineInstructions(): ?string
    {
        $instructions = config('resrv-config.manual_reservations_offline_instructions');

        return is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;
    }

    public function render()
    {
        if ($this->paymentView) {
            return view($this->paymentView);
        }

        return view('statamic-resrv::livewire.reservation-payment');
    }
}

<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Exceptions\ReservationDriftException;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Exceptions\ReservationExpiredException;
use Reach\StatamicResrv\Exceptions\ReservationTerminatedException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Reservation;

class Checkout extends Component
{
    use Traits\HandlesExtrasQueries,
        Traits\HandlesOptionsQueries,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    #[Locked]
    public EnabledExtras $enabledExtras;

    #[Locked]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public string $clientSecret;

    #[Locked]
    public string $publicKey;

    #[Locked]
    public string $paymentView = '';

    public string $selectedGateway = '';

    public array $availableGateways = [];

    #[Locked]
    public $coupon;

    #[Locked]
    public bool $enableCoupon = true;

    public int $step = 1;

    public bool $enableExtrasStep = true;

    public $reservationError = false;

    public function mount()
    {
        try {
            $this->reservation();
        } catch (ReservationTerminatedException $e) {
            // CONFIRMED / PARTNER / REFUNDED → the user already completed the flow (or is
            // returning via back button after a successful checkout). Redirect to the
            // checkout-completed entry rather than showing a terminal error page.
            return $this->redirectToCheckoutComplete(session('resrv_reservation'));
        } catch (ReservationException $e) {
            // ReservationExpiredException + any other ReservationException land here —
            // full-page terminal error view.
            $this->reservationError = $e->getMessage();
        }

        // A refresh drops step/selectedGateway back to defaults but leaves any prior step-3
        // payment_surcharge (and active Stripe intent) persisted on the reservation. Roll them
        // back so the sidebar doesn't render a stale gateway fee / inflated payable-now, and
        // so a lingering intent doesn't survive into the next pass through checkout.
        if (! $this->reservationError) {
            $this->resetPaymentState();
        }

        $this->initializeExtrasAndOptions();

        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }

        $this->coupon = session('resrv_coupon') ?? null;
    }

    /**
     * Redirect to the configured checkout-completed entry. Used when the user lands on the
     * checkout with a reservation that is already in a terminal-confirmed state.
     */
    protected function redirectToCheckoutComplete(?int $reservationId = null)
    {
        $id = $reservationId ?? (isset($this->reservation) ? $this->reservation->id : null);
        $url = $this->getCheckoutCompleteEntry()->absoluteUrl();

        if ($id) {
            $url .= '?payment_pending='.$id;
        }

        return redirect()->to($url);
    }

    #[Computed]
    public function reservation()
    {
        return $this->getReservation();
    }

    #[Computed]
    public function entry()
    {
        return $this->getEntry($this->reservation->item_id);
    }

    public function goToStep(int $step): void
    {
        if ($step < 3 && $this->selectedGateway !== '') {
            $this->resetPaymentState();
        }
        $this->step = $step;
    }

    public function initializeExtrasAndOptions(): void
    {
        // When extras step is disabled, load from session if available
        if ($this->enableExtrasStep === false) {
            if (session()->has('resrv-extras')) {
                $this->enabledExtras->fill(session('resrv-extras'));
            } else {
                $this->enabledExtras->extras = collect();
            }
            if (session()->has('resrv-options')) {
                $this->enabledOptions->fill(session('resrv-options'));
            } else {
                $this->enabledOptions->options = collect();
            }
        } else {
            $this->enabledExtras->extras = collect();
            $this->enabledOptions->options = collect();
        }
    }

    public function handleFirstStep()
    {
        // Validate data
        $this->validate();

        // Confirm that the reservation data is valid by cross-checking with the database.
        // Terminal checks run BEFORE drift checks — otherwise an expired reservation whose
        // stale data also drifted would get demoted to a recoverable banner because the
        // drift exception would be thrown first.
        try {
            $this->confirmReservationHasNotExpired();
            $this->confirmReservationIsValid();
        } catch (ReservationExpiredException $e) {
            $this->reservationError = $e->getMessage();

            return;
        } catch (ReservationTerminatedException $e) {
            return $this->redirectToCheckoutComplete();
        } catch (OptionsException $e) {
            $this->addError('options', $e->getMessage());

            return;
        } catch (ExtrasException $e) {
            $this->addError('extras', $e->getMessage());

            return;
        } catch (ReservationDriftException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        $totals = $this->calculateReservationTotals();

        $toUpdate = [
            'total' => $totals->get('total')->format(),
        ];

        if (config('resrv-config.payment') == 'everything' || ! $this->freeCancellationPossible()) {
            $toUpdate['payment'] = $totals->get('total')->format();
            // Writing a fresh base payment — clear any stale surcharge from a previous step-3 pass
            $toUpdate['payment_surcharge'] = 0;
        }

        // Update the reservation with the total
        $this->reservation->update($toUpdate);

        // Sync extras & options to the database
        $this->assignExtras();
        $this->assignOptions();

        $this->step = 2;
    }

    #[On('checkout-form-submitted')]
    public function handleSecondStep()
    {
        $this->resetErrorBag('reservation');

        // Make sure the reservation is not expired — wrap every subsequent $this->reservation
        // access too, because the getReservation() time-check inside the computed can itself
        // throw ReservationExpiredException (the reservation may have aged past
        // minutes_to_hold between mount and this submit).
        try {
            $this->confirmReservationHasNotExpired();

            // If the payment amount is zero, just show the confirmation page
            if ($this->reservationPaymentIsZero()) {
                return $this->handleReservationWithZeroPayment();
            }
        } catch (ReservationExpiredException $e) {
            $this->reservationError = $e->getMessage();

            return;
        } catch (ReservationTerminatedException $e) {
            return $this->redirectToCheckoutComplete();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        // Reset any stale payment state from a previous pass through checkout
        $this->resetPaymentState();

        // Populate available gateways, filtered by the current payment amount
        $manager = app(PaymentGatewayManager::class);
        $reservation = $this->reservation->fresh();
        $this->availableGateways = $manager->availableForFrontend($reservation->payment);

        // No gateway accepts this amount — surface an error and stay on step 2
        if (empty($this->availableGateways)) {
            $this->addError('reservation', __('statamic-resrv::frontend.noGatewayAvailableForAmount'));

            return;
        }

        // If only one surviving gateway, auto-select and initialize payment
        if (count($this->availableGateways) === 1) {
            $this->selectedGateway = $this->availableGateways[0]['name'];

            return $this->initializePayment();
        }

        // Multiple gateways — show picker
        $this->step = 3;
    }

    #[On('gateway-selected')]
    public function selectGateway(string $gateway)
    {
        $this->resetErrorBag('reservation');

        $manager = app(PaymentGatewayManager::class);

        if (! $manager->has($gateway)) {
            $this->addError('reservation', __('Invalid payment gateway selected.'));

            return;
        }

        $reservation = $this->reservation->fresh();
        if (! $manager->isAvailableFor($gateway, $reservation->payment)) {
            // The clicked gateway is being rejected — fully reset payment state (intent + surcharge)
            // carried over from a prior tab or browser-back copy of step 3, so a later webhook can't
            // act on the stale intent and the sidebar doesn't show an inflated payable-now from the
            // previous gateway's surcharge.
            $this->resetPaymentState();
            $this->availableGateways = $manager->availableForFrontend($reservation->payment);

            // No gateway accepts the current amount — bounce back to step 2 with the no-gateway error
            if (empty($this->availableGateways)) {
                $this->addError('reservation', __('statamic-resrv::frontend.noGatewayAvailableForAmount'));
                $this->step = 2;

                return;
            }

            // Single surviving gateway — auto-select and initialize payment
            if (count($this->availableGateways) === 1) {
                $this->selectedGateway = $this->availableGateways[0]['name'];

                return $this->initializePayment();
            }

            // Multiple still available — keep the picker visible with a contextual error
            $this->addError('reservation', __('statamic-resrv::frontend.gatewayNotAvailableForAmount'));

            return;
        }

        // A prior gateway selection at this step may have created an intent we're now abandoning.
        $this->cancelActiveIntent($reservation);

        $this->selectedGateway = $gateway;

        return $this->initializePayment();
    }

    public function resetPaymentState(): void
    {
        $reservation = $this->reservation->fresh();

        // MUST NOT wipe payment_id/gateway or cancel the intent for non-PENDING reservations —
        // StripePaymentGateway::refund() needs payment_id to call Stripe, and cancelling a
        // live intent for a confirmed reservation would unwind a completed booking.
        if ($reservation->status !== ReservationStatus::PENDING->value) {
            return;
        }

        $this->cancelActiveIntent($reservation);

        // selectedGateway resets on page refresh but payment_surcharge persists in DB
        if (! $reservation->payment_surcharge->isZero()) {
            $reservation->update(['payment_surcharge' => 0]);
            unset($this->reservation);
        }

        $this->selectedGateway = '';
        $this->clientSecret = '';
        $this->publicKey = '';
        $this->paymentView = '';
    }

    /**
     * Callers must pass a fresh reservation instance (e.g. via `$this->reservation->fresh()`)
     * so status and payment-field checks reflect committed DB state.
     */
    protected function cancelActiveIntent(Reservation $reservation): void
    {
        // Belt-and-braces for future callers that skip the guard in resetPaymentState.
        if ($reservation->status !== ReservationStatus::PENDING->value) {
            return;
        }

        if ($reservation->payment_id === '' || empty($reservation->payment_gateway)) {
            return;
        }

        $manager = app(PaymentGatewayManager::class);

        if (! $manager->has($reservation->payment_gateway)) {
            return;
        }

        $oldPaymentId = $reservation->payment_id;
        $gateway = $manager->gateway($reservation->payment_gateway);

        // Clear payment_id AND payment_gateway together before calling the gateway so a
        // cancellation webhook from the old intent can't look up the reservation and fire
        // ReservationCancelled against it. payment_id and payment_gateway must be paired —
        // a lingering payment_gateway without a payment_id leaves state inconsistent and
        // can misroute future operations through forReservation().
        $reservation->update(['payment_id' => '', 'payment_gateway' => '']);
        unset($this->reservation);

        try {
            $gateway->cancelPaymentIntent($oldPaymentId, $reservation);
        } catch (\Throwable $e) {
            Log::warning('Failed to cancel stale payment intent: '.$e->getMessage(), [
                'reservation_id' => $reservation->id,
                'payment_id' => $oldPaymentId,
            ]);
        }
    }

    protected function applySurcharge(PaymentGatewayManager $manager, string $gateway): void
    {
        $reservation = $this->reservation->fresh();
        $surcharge = $manager->calculateSurcharge($gateway, $reservation->payment);

        $reservation->update([
            'payment_surcharge' => $surcharge->format(),
        ]);

        unset($this->reservation);
    }

    protected function initializePayment()
    {
        // Get a fresh record from the database
        $reservation = $this->reservation->fresh();

        // Resolve the selected gateway
        $manager = app(PaymentGatewayManager::class);
        $payment = $manager->gateway($this->selectedGateway);

        $surcharge = $manager->calculateSurcharge($this->selectedGateway, $reservation->payment);
        $totalToCharge = $reservation->payment->add($surcharge);

        // Set the public key
        $this->publicKey = $payment->getPublicKey($reservation);

        // Set the payment view
        $this->paymentView = $payment->paymentView();

        // Create a payment intent with the full amount (including surcharge) before touching the DB
        $paymentIndent = $payment->paymentIntent($totalToCharge, $reservation, $reservation->customerData);

        // `payment` stays as the reservation amount (read by CP, API, emails). Only the surcharge
        // and intent id get persisted — the gateway receives the full total via $totalToCharge above.
        $reservation->update([
            'payment_surcharge' => $surcharge->format(),
            'payment_gateway' => $this->selectedGateway,
            'payment_id' => $paymentIndent->id,
        ]);
        unset($this->reservation);

        // If the payment method needs to redirect to another website do so
        if ($payment->redirectsForPayment()) {
            $redirectUrl = $paymentIndent->redirectTo;
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';
            $redirectUrl .= $separator.http_build_query(['resrv_gateway' => $this->selectedGateway]);

            return redirect()->away($redirectUrl);
        }

        // Set it in a public property so that we can access it at the payment step
        $this->clientSecret = $paymentIndent->client_secret;

        $this->step = 3;
    }

    #[On('checkout-form-submitted-without-payment')]
    public function handleReservationWithoutPayment()
    {
        // Make sure the reservation is not expired
        try {
            $this->confirmReservationHasNotExpired();
        } catch (ReservationExpiredException $e) {
            $this->reservationError = $e->getMessage();

            return;
        } catch (ReservationTerminatedException $e) {
            return $this->redirectToCheckoutComplete();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        // Get a fresh record from the database
        $reservation = $this->reservation->fresh();

        // Ensure once again that the affiliate can confirm the reservation without payment
        if (! $this->affiliateCanSkipPayment()) {
            $this->addError('reservation', 'You cannot confirm this reservation without payment. Please clear your cookies and try again.');

            return;
        }

        // Transition to PARTNER (terminal-equivalent for affiliate bookings) and dispatch the
        // confirmed event for side effects (emails) only if the transition actually happened.
        if ($reservation->transitionTo(ReservationStatus::PARTNER)) {
            ReservationConfirmed::dispatch($reservation);
        }

        return $this->redirectToCheckoutComplete($reservation->id);
    }

    protected function handleReservationWithZeroPayment()
    {
        // Ensure once again that the total is zero
        if (! $this->reservationPaymentIsZero()) {
            $this->addError('reservation', 'We cannot confirm this reservation. Please try again.');

            return;
        }

        if ($this->reservation->transitionTo(ReservationStatus::CONFIRMED)) {
            ReservationConfirmed::dispatch($this->reservation);
        }

        return $this->redirectToCheckoutComplete($this->reservation->id);
    }

    protected function confirmReservationIsValid(): void
    {
        $totals = $this->calculateReservationTotals();

        // Confirm everything is OK before we move on
        $this->reservation->validateReservation(array_merge(
            $this->getAvailabilityDataFromReservation(),
            [
                'payment' => $this->reservation->payment,
                'price' => $this->reservation->price,
                'total' => $totals->get('total'),
                'extras' => $this->enabledExtras->extras,
                'options' => $this->enabledOptions->options,
                'customer' => $this->reservation->customerData ?? collect(),
            ],
        ), $this->entry->id());
    }

    protected function confirmReservationHasNotExpired(): void
    {
        $expireAt = Carbon::parse($this->reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');
        if ($expireAt < Carbon::now() || $this->reservation->fresh()->status === ReservationStatus::EXPIRED->value) {
            throw new ReservationExpiredException('This reservation has expired. Please start over.');
        }
    }

    public function addCoupon(string $coupon)
    {
        $data = validator(['coupon' => $coupon], ['coupon' => 'required|alpha_dash'], ['coupon' => 'The coupon code is invalid.'])->validate();

        try {
            $couponModel = DynamicPricing::searchForCoupon($data['coupon'], $this->reservation->id);
        } catch (CouponNotFoundException $exception) {
            $this->addError('coupon', $exception->getMessage());

            return;
        }
        session(['resrv_coupon' => $data['coupon']]);
        $this->coupon = $data['coupon'];
        $this->resetValidation('coupon');
        $this->dispatch('coupon-applied', $this->coupon);
    }

    public function removeCoupon()
    {
        $this->dispatch('coupon-removed', $this->coupon, true);
        session()->forget('resrv_coupon');
        $this->coupon = null;
    }

    #[On('extras-updated')]
    public function updateExtras($extras): void
    {
        $this->enabledExtras->extras = collect($extras);
    }

    #[On('options-updated')]
    public function updateOptions($options): void
    {
        $this->enabledOptions->options = collect($options);
    }

    protected function assignExtras(): void
    {
        if ($this->enabledExtras->extras->count() > 0) {
            try {
                $this->reservation->extras()->sync($this->enabledExtras->extrasToSync());
            } catch (\Exception $e) {
                $this->addError('extras', 'There was an error assigning the extras. Please try again.');

                return;
            }
        }
    }

    protected function assignOptions(): void
    {
        if ($this->enabledOptions->options->count() > 0) {
            try {
                $this->reservation->options()->sync($this->enabledOptions->optionsToSync());
            } catch (\Exception $e) {
                $this->addError('options', 'There was an error assigning the options. Please try again.');

                return;
            }
        }
    }

    #[On('coupon-applied'), On('coupon-removed')]
    public function updateTotals($coupon, $removeCoupon = false): void
    {
        // This try-catch block is here to prevent an error if a user tries to remove a coupon that has been deleted
        try {
            $couponModel = DynamicPricing::searchForCoupon($coupon, $this->reservation->id);

            if ($couponModel->appliesToExtras()) {
                $this->dispatch('extras-coupon-changed');
            }
        } catch (CouponNotFoundException $exception) {
            // Dispatch the event anyway
            $this->dispatch('extras-coupon-changed');
        }

        // Get the prices after applying the coupon
        $prices = $this->getUpdatedPrices();
        // Update the reservation with the new prices; clear stale surcharge so it can't desync from payment
        $this->reservation->update([
            'price' => $prices['price'],
            'payment' => $prices['payment'],
            'payment_surcharge' => 0,
        ]);
        // Remove the caches
        unset($this->reservation);

        // If a gateway is still selected, recompute the surcharge against the new base payment
        if ($this->selectedGateway !== '') {
            $this->applySurcharge(app(PaymentGatewayManager::class), $this->selectedGateway);
        }

        // Calculate and update the total
        $totals = $this->calculateReservationTotals();
        $this->reservation->update(['total' => $totals->get('total')->format()]);

        CouponUpdated::dispatch($this->reservation, $coupon, $removeCoupon);
    }

    public function render()
    {
        if ($this->reservationError) {
            return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
        }

        return view('statamic-resrv::livewire.'.$this->view);
    }
}

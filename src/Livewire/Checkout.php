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
use Reach\StatamicResrv\Exceptions\ReservationException;
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
        } catch (ReservationException $e) {
            $this->reservationError = $e->getMessage();
        }

        // A refresh drops step/selectedGateway back to defaults but leaves any prior step-3
        // payment_surcharge (and active Stripe intent) persisted on the reservation. Roll them
        // back so the sidebar doesn't render a stale gateway fee / inflated payable-now, and
        // so a lingering intent doesn't survive into the next pass through checkout.
        if (! $this->reservationError) {
            $this->resetPaymentState();
        } elseif (
            ($expiredReservation = Reservation::find(session('resrv_reservation')))
            && $expiredReservation->status === ReservationStatus::PENDING->value
        ) {
            // Only time-expired PENDING reservations land here — getReservation() only throws
            // for still-pending rows via the new minutes_to_hold check. Cancel the dangling
            // step-3 intent so a late success webhook can't confirm an orphaned reservation.
            // Confirmed / webhook-paid / DB-expired reservations keep their payment_id intact
            // so StripePaymentGateway::refund() and other reconciliation paths still work.
            $this->cancelActiveIntent($expiredReservation);
        }

        $this->initializeExtrasAndOptions();

        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }

        $this->coupon = session('resrv_coupon') ?? null;
    }

    #[Computed(persist: true)]
    public function reservation()
    {
        return $this->getReservation();
    }

    #[Computed(persist: true)]
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

    public function handleFirstStep(): void
    {
        // Validate data
        $this->validate();

        // Check terminal expiration FIRST. confirmReservationIsValid() below touches
        // $this->reservation, which re-fires getReservation() and throws a ReservationException
        // with the exact same "expired" message — if we ran validation first, that throw would
        // be swallowed by the recoverable ReservationException catch and demoted to an inline
        // error, never flipping $reservationError. Ordering matches handleSecondStep().
        try {
            $this->confirmReservationHasNotExpired();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());
            $this->reservationError = $e->getMessage();

            return;
        }

        // Recoverable validation failures (price/availability drift, max quantity, missing
        // required extras/options) — surface them as inline banner errors so the user can
        // correct and retry. Do NOT flip $reservationError to terminal here.
        try {
            $this->confirmReservationIsValid();
        } catch (OptionsException $e) {
            $this->addError('options', $e->getMessage());

            return;
        } catch (ExtrasException $e) {
            $this->addError('extras', $e->getMessage());

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

        // If the payment amount is zero, just show the confirmation page
        if ($this->reservationPaymentIsZero()) {
            return $this->handleReservationWithZeroPayment();
        }

        // Make sure the reservation is not expired
        try {
            $this->confirmReservationHasNotExpired();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());
            $this->reservationError = $e->getMessage();

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
        $this->cancelActiveIntent();

        $this->selectedGateway = $gateway;

        return $this->initializePayment();
    }

    public function resetPaymentState(): void
    {
        $this->cancelActiveIntent();

        // Guard on persisted state only — selectedGateway resets on page refresh but payment_surcharge persists in DB
        if (! $this->reservation->payment_surcharge->isZero()) {
            $this->reservation->fresh()->update(['payment_surcharge' => 0]);
            unset($this->reservation);
        }

        $this->selectedGateway = '';
        $this->clientSecret = '';
        $this->publicKey = '';
        $this->paymentView = '';
    }

    protected function cancelActiveIntent(?Reservation $reservation = null): void
    {
        $reservation = $reservation ? $reservation->fresh() : $this->reservation->fresh();

        if ($reservation->payment_id === '' || empty($reservation->payment_gateway)) {
            return;
        }

        $manager = app(PaymentGatewayManager::class);

        if (! $manager->has($reservation->payment_gateway)) {
            return;
        }

        $oldPaymentId = $reservation->payment_id;
        $gateway = $manager->gateway($reservation->payment_gateway);

        // Clear payment_id before calling the gateway so a cancellation webhook from the old intent
        // can't look up the reservation and fire ReservationCancelled against it.
        $reservation->update(['payment_id' => '']);
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
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());
            $this->reservationError = $e->getMessage();

            return;
        }

        // Get a fresh record from the database
        $reservation = $this->reservation->fresh();

        // Ensure once again that the affiliate can confirm the reservation without payment
        if (! $this->affiliateCanSkipPayment()) {
            $this->addError('reservation', 'You cannot confirm this reservation without payment. Please clear your cookies and try again.');

            return;
        }

        // Update the reservation status
        $reservation->update(['status' => 'partner']);
        ReservationConfirmed::dispatch($reservation);

        return redirect()->to($this->getCheckoutCompleteEntry()->absoluteUrl().'?payment_pending='.$reservation->id);
    }

    protected function handleReservationWithZeroPayment()
    {
        // Ensure once again that the total is zero
        if (! $this->reservationPaymentIsZero()) {
            $this->addError('reservation', 'We cannot confirm this reservation. Please try again.');

            return;
        }

        // Update the reservation status
        ReservationConfirmed::dispatch($this->reservation);

        return redirect()->to($this->getCheckoutCompleteEntry()->absoluteUrl().'?payment_pending='.$this->reservation->id);
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
        if ($expireAt < Carbon::now() || $this->reservation->fresh()->status === 'expired') {
            throw new ReservationException('This reservation has expired. Please start over.');
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

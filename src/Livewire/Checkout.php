<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\DynamicPricing;

class Checkout extends Component
{
    use Traits\HandlesExtrasQueries,
        Traits\HandlesOptionsQueries,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Locked]
    public Collection $extraConditions;

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public string $clientSecret;

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
        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }
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
        $this->extraConditions = collect();
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

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        return $this->getExtrasForReservation();
    }

    #[Computed(persist: true)]
    public function frontendExtras(): Collection
    {
        return $this->extras->groupBy('category_id')
            ->sortBy('order')
            ->map(function ($items, $categoryId) {
                $category = new \stdClass;
                $category->id = $categoryId ?? null;
                $category->name = $items->first()->category?->name ?? 'Uncategorized';
                $category->slug = $items->first()->category?->slug ?? 'uncategorized';
                $category->description = $items->first()->category?->description ?? null;
                $category->extras = $items;

                return $category;
            })->values();
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        return $this->getOptionsForReservation();
    }

    public function goToStep(int $step): void
    {
        $this->step = $step;
    }

    public function handleFirstStep(): void
    {
        // Validate data
        $this->validate();

        // Confirm that the reservation data is valid by cross-checking with the database
        try {
            $this->confirmReservationIsValid();
            $this->confirmReservationHasNotExpired();
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
        // If the payment amount is zero, just show the confirmation page
        if ($this->reservationPaymentIsZero()) {
            return $this->handleReservationWithZeroPayment();
        }

        // Make sure the reservation is not expired
        try {
            $this->confirmReservationHasNotExpired();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        // Get a fresh record from the database
        $reservation = $this->reservation->fresh();

        // Get an instance of the payment interface
        $payment = app(PaymentInterface::class);

        // Create a payment intent
        $paymentIndent = $payment->paymentIntent($reservation->payment, $reservation, $reservation->customer);

        // Save it in the database
        $reservation->update(['payment_id' => $paymentIndent->id]);

        // If the payment method needs to redirect to another website do so
        if ($payment->redirectsForPayment()) {
            return redirect()->away($paymentIndent->redirectTo);
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
                'customer' => $this->reservation->customer ?? collect(),
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

    protected function assignExtras(): void
    {
        if ($this->enabledExtras->extras->count() > 0) {
            $this->reservation->extras()->sync($this->enabledExtras->extrasToSync());
        }
    }

    protected function assignOptions(): void
    {
        if ($this->enabledOptions->options->count() > 0) {
            $this->reservation->options()->sync($this->enabledOptions->optionsToSync());
        }
    }

    public function updatedEnabledExtras()
    {
        $this->handleExtrasConditions($this->extras);
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

    #[On('coupon-applied'), On('coupon-removed')]
    public function updateTotals($coupon, $removeCoupon = false): void
    {
        // Get the prices after applying the coupon
        $prices = $this->getUpdatedPrices();
        // Update the reservation with the new prices
        $this->reservation->update(['price' => $prices['price'], 'payment' => $prices['payment']]);
        // Remove the caches
        unset($this->reservation);
        unset($this->extras);
        unset($this->options);
        // Update pricing
        $this->updateEnabledExtraPrices();
        $this->calculateReservationTotals();
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

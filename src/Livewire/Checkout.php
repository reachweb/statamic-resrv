<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
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

    public function addCoupon(string $coupon)
    {
        $data = validator(['coupon' => $coupon], ['coupon' => 'required|alpha_dash'], ['coupon' => 'The coupon code is invalid.'])->validate();

        try {
            DynamicPricing::searchForCoupon($data['coupon'], $this->reservation->id);
        } catch (CouponNotFoundException $exception) {
            $this->addError('coupon', $exception->getMessage());

            return;
        }
        session(['resrv_coupon' => $data['coupon']]);
        $this->coupon = $data['coupon'];
        $this->resetValidation('coupon');
        $this->dispatch('coupon-applied');
    }

    public function removeCoupon()
    {
        session()->forget('resrv_coupon');
        $this->coupon = null;
        $this->dispatch('coupon-removed');
    }

    #[On('coupon-applied'), On('coupon-removed')]
    public function updateTotals(): void
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
    }

    public function render()
    {
        if ($this->reservationError) {
            return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
        }

        return view('statamic-resrv::livewire.'.$this->view);
    }
}

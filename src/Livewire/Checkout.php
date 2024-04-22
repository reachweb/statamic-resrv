<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Reservation;

class Checkout extends Component
{
    use Traits\HandlesExtrasQueries, Traits\HandlesOptionsQueries, Traits\HandlesReservationQueries, Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    #[Validate]
    public Collection $enabledExtras;

    #[Validate]
    public Collection $enabledOptions;

    #[Locked]
    public string $clientSecret;

    #[Locked]
    public string $coupon;

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
        $this->enabledExtras = collect();
        $this->enabledOptions = collect();
        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }        
    }

    #[Computed(persist: true)]
    public function reservation()
    {
        return $this->getReservation();
    }

    #[Computed(persist: true)]
    public function entry()
    {
        if (! $this->reservation) {
            return;
        }
        return $this->getEntry($this->reservation->item_id);
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        if (! $this->reservation) {
            return collect();
        }
        return $this->getExtrasForEntry();
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        if (! $this->reservation) {
            return collect();
        }
        return $this->getOptionsForEntry();
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

        // Update the reservation with the total
        $this->reservation->update(['total' => $this->calculateTotals()->get('total')->format()]);

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
        $totals = $this->calculateTotals();

        // Confirm everything is OK before we move on
        $this->reservation->validateReservation(array_merge(
            $this->getAvailabilityDataFromReservation(),
            [
                'payment' => $this->reservation->payment,
                'price' => $this->reservation->price,
                'total' => $totals->get('total'),
                'extras' => $this->enabledExtras,
                'options' => $this->enabledOptions,
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
        if ($this->enabledExtras->count() > 0) {
            $extrasToSync = $this->enabledExtras->mapWithKeys(function ($extra) {
                return [
                    $extra['id'] => [
                        'quantity' => $extra['quantity'],
                        'price' => $extra['price'],
                    ],
                ];
            });
            $this->reservation->extras()->sync($extrasToSync);
        }
    }

    protected function assignOptions(): void
    {
        if ($this->enabledOptions->count() > 0) {
            $optionsToSync = $this->enabledOptions->mapWithKeys(function ($option) {
                return [
                    $option['id'] => ['value' => $option['value']],
                ];
            });
            $this->reservation->options()->sync($optionsToSync);
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
        $this->dispatch('coupon-applied');
    }

    public function calculateTotals(): Collection
    {
        // Init totals
        $total = Price::create(0);
        $extrasTotal = Price::create(0);
        $optionsTotal = Price::create(0);

        $reservationTotal = $this->reservation->price;

        // Calculate totals
        if ($this->enabledExtras->count() > 0) {
            $extrasTotal = $extrasTotal->add(...$this->enabledExtras->map(fn ($extra) => Price::create($extra['price'])->multiply($extra['quantity']))->toArray());
        }
        if ($this->enabledOptions->count() > 0) {
            $optionsTotal = $optionsTotal->add(...$this->enabledOptions
                ->map(fn ($option) => Price::create($option['price']))
                ->toArray()
            );
        }
        $total = $total->add($reservationTotal, $extrasTotal, $optionsTotal);

        $payment = $this->reservation->payment;

        return collect(compact('total', 'reservationTotal', 'extrasTotal', 'optionsTotal', 'payment'));
    }

    public function rules(): array
    {
        return [
            'enabledExtras' => 'nullable|array',
            'enabledExtras.*.id' => [
                'required',
                'integer',
            ],
            'enabledExtras.*.price' => [
                'required',
                'numeric',
            ],
            'enabledExtras.*.quantity' => [
                'required',
                'integer',
            ],
            'enabledOptions' => 'nullable|array',
            'enabledOptions.*.id' => [
                'required',
                'integer',
            ],
            'enabledOptions.*.price' => [
                'required',
                'numeric',
            ],
            'enabledOptions.*.value' => [
                'required',
                'integer',
            ],
        ];
    }

    public function render()
    {
        if ($this->reservationError) {
            return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
        }
        return view('statamic-resrv::livewire.'.$this->view);     
    }
}

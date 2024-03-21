<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;

class Checkout extends Component
{
    use Traits\HandlesExtrasQueries, Traits\HandlesReservationQueries, Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    #[Validate]
    public Collection $enabledExtras;

    public Collection $options;

    public Collection $enabledOptions;

    public int $step = 1;

    public bool $enableExtrasStep = true;

    public function mount()
    {
        $this->enabledExtras = collect();
        $this->options = collect();
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
        return $this->getEntry($this->reservation->item_id);
    }

    #[Computed(persist: true)]
    public function extras()
    {
        return $this->getExtrasForEntry();
    }

    public function handleFirstStep()
    {
        // Validate data
        $this->validate();

        // Confirm that the reservation data is valid but cross-checking with the database
        try {
            $this->confirmReservationIsValid();
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

    protected function confirmReservationIsValid()
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

    protected function assignExtras()
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

    protected function assignOptions()
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

    public function calculateTotals()
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
            $optionsTotal = $optionsTotal->add(...$this->enabledOptions->map(fn ($extra) => Price::create($extra['price']))->toArray());
        }
        $total = $total->add($reservationTotal, $extrasTotal, $optionsTotal);

        $payment = $this->reservation->payment;

        return collect(compact('total', 'reservationTotal', 'extrasTotal', 'optionsTotal', 'payment'));
    }

    public function rules()
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
        ];
    }

    public function checkout()
    {
        if ($this->step === 1) {
            $this->handleFirstStep();
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

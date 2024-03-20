<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Reach\StatamicResrv\Facades\Price;

class Checkout extends Component
{
    use Traits\HandlesExtrasQueries, Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

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
    public function checkoutForm()
    {
        return $this->reservation->getCheckoutForm();
    }

    #[Computed(persist: true)]
    public function extras()
    {
        return $this->getExtrasForEntry();
    }

    public function calculateTotals()
    {
        // Init totals
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
        $total = $reservationTotal->add($extrasTotal, $optionsTotal);

        $payment = $this->reservation->payment;

        return collect(compact('total', 'reservationTotal', 'extrasTotal', 'optionsTotal', 'payment'));
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

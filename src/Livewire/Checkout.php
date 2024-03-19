<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Checkout extends Component
{
    use Traits\HandlesStatamicQueries, Traits\HandlesExtrasQueries;

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
        //
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

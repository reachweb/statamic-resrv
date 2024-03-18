<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Checkout extends Component
{
    use Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    public Collection $extras;

    public Collection $options;

    #[Computed(persist: true)]
    public function reservation()
    {
        return $this->getReservation();
    }

    #[Computed(persist: true)]
    public function entry()
    {
        return $this->getEntry($this->reservation->item_id)->toAugmentedArray();
    }

    #[Computed(persist: true)]
    public function checkoutForm()
    {
        return $this->reservation->getCheckoutForm();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

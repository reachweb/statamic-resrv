<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CheckoutExtras extends Component
{
    use Traits\HandlesExtrasQueries, Traits\HandlesStatamicQueries;

    public string $view = 'checkout-extras';

    #[Locked]
    public int $reservationId;

    public Collection $enabledExtras;

    #[Computed(persist: true)]
    public function extras()
    {
        return $this->getExtrasForEntry();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

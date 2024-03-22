<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;

class CheckoutOptions extends Component
{
    public string $view = 'checkout-options';

    #[Locked]
    public Collection $options;

    #[Modelable, Locked]
    public Collection $enabledOptions;

    #[On('option-changed')]
    public function optionChanged($option): void
    {
        $this->enabledOptions->put($option['id'], $option);
    }

    public function findAlreadySelected($id)
    {
        //return $this->enabledOptions->get($id);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

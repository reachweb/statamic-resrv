<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;

class CheckoutExtras extends Component
{
    public string $view = 'checkout-extras';

    #[Locked]
    public Collection $extras;

    #[Modelable, Locked]
    public Collection $enabledExtras;

    #[On('extra-changed')]
    public function extraChanged($extra)
    {
        if ($extra['quantity'] === 0) {
            $this->removeFromEnabledExtras($extra);
        } else {
            $this->addToEnabledExtras($extra);
        }
    }

    protected function addToEnabledExtras($extra)
    {
        if (! $this->enabledExtras->contains('id', $extra['id'])) {
            $this->enabledExtras->push($extra);
        } else {
            $this->enabledExtras->transform(function ($enabledExtra) use ($extra) {
                if ($enabledExtra['id'] === $extra['id']) {
                    $enabledExtra['quantity'] = $extra['quantity'];
                }

                return $enabledExtra;
            });
        }
    }

    protected function removeFromEnabledExtras($extra)
    {
        $this->enabledExtras = $this->enabledExtras->reject(fn ($enabledExtra) => $enabledExtra['id'] === $extra['id']);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

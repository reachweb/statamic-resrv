<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Extra extends Component
{
    public string $view = 'extra';

    #[Locked]
    public $extra;

    #[Validate('required|integer|min:1')]
    public int $quantity = 0;

    #[Validate('required|boolean')]
    public bool $selected = false;

    public function mount($alreadySelected = false)
    {
        if ($alreadySelected) {
            $this->selected = true;
            $this->quantity = $alreadySelected['quantity'];
        }
    }

    public function updatedSelected()
    {
        if ($this->selected) {
            $this->quantity = 1;
        } else {
            $this->quantity = 0;
        }
        $this->dispatchExtra();
    }

    public function updatedQuantity()
    {
        $this->dispatchExtra();
    }

    public function dispatchExtra()
    {
        $this->dispatch('extra-changed', [
            'id' => $this->extra->id,
            'price' => $this->extra->price,
            'quantity' => $this->quantity,
        ]);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

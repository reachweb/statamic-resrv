<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Option extends Component
{
    public string $view = 'option';

    #[Locked]
    public $option;

    #[Validate('required|string')]
    public string $selected = '';

    public function mount($alreadySelected = false)
    {
        if ($alreadySelected) {
            $this->selected = $alreadySelected['value'];
        }
    }

    public function updatedSelected()
    {
        $values = collect($this->option['values']);
        $this->dispatch('option-changed', [
            'id' => $this->option['id'],
            'price' => $values->firstWhere('id', $this->selected)['price'],
            'value' => $this->selected,
        ])->to(CheckoutOptions::class);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

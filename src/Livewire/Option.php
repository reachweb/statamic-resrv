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
        ray($this->option);
        if ($alreadySelected) {
            $this->selected = $alreadySelected['value'];
        }
    }

    public function updatedSelected()
    {
        ray($this->option);
        $this->dispatch('option-changed', [
            'id' => $this->option->id,
            'price' => $this->option->values->firstWhere('id', $this->selected)->price->format(),
            'value' => $this->selected,
        ])->to(CheckoutOptions::class);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

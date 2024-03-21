<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CheckoutForm extends Component
{
    public string $view = 'checkout-form';

    #[Locked]
    public array $checkoutForm;

    #[Validate]
    public array $form;

    public function mount() {
        $this->form = collect($this->checkoutForm)->mapWithKeys(function ($field) {
            return [
                $field['handle'] => $field['type'] === 'checkboxes' ? [] : '',
            ];
        })->all();
    }

    public function rules()
    {
        return collect($this->checkoutForm)->mapWithKeys(function ($field) {
            return ['form.'.$field['handle'] => isset($field['validate']) ? implode('|', $field['validate']) : 'nullable'];
        })->all();
    }

    public function validationAttributes()
    {
        return collect($this->checkoutForm)->mapWithKeys(fn ($field) => ['form.'.$field['handle'] => $field['display']])->all();
    }

    public function submit() {
        $this->validate();
        $this->dispatch('checkout-form-submitted', $this->form);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

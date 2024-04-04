<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Reach\StatamicResrv\Models\Reservation;

class CheckoutForm extends Component
{
    public string $view = 'checkout-form';

    #[Locked]
    public Reservation $reservation;

    #[Validate]
    public array $form;

    public function mount(Reservation $reservation): void
    {
        $this->reservation = $reservation->fresh();
        if ($this->reservation->customer) {
            $this->form = $this->reservation->customer->all();
        } else {
            $this->initializeForm();
        }
    }

    #[Computed(persist: true)]
    public function checkoutForm()
    {
        return $this->reservation->getCheckoutForm()->toArray();
    }

    public function initializeForm()
    {
        $custom = [];
        if (session()->has('resrv-search')) {
            $custom = session('resrv-search')->custom ?? [];
        }

        $this->form = collect($this->checkoutForm)->mapWithKeys(function ($field) use ($custom) {
            // Default to an empty string or an empty array based on the field type
            $value = $field['type'] === 'checkboxes' ? [] : '';

            // If the field is in the custom session data, prepopulate that value
            if (array_key_exists($field['handle'], $custom)) {
                $value = $custom[$field['handle']];
            }

            return [
                $field['handle'] => $value,
            ];
        })->all();
    }

    public function rules(): array
    {
        return collect($this->checkoutForm)->mapWithKeys(function ($field) {
            return ['form.'.$field['handle'] => isset($field['validate']) ? implode('|', $field['validate']) : 'nullable'];
        })->all();
    }

    public function validationAttributes(): array
    {
        return collect($this->checkoutForm)->mapWithKeys(fn ($field) => ['form.'.$field['handle'] => $field['display']])->all();
    }

    public function saveCustomer()
    {
        $this->reservation->update(['customer' => $this->form]);
    }

    public function submit(): void
    {
        $this->validate();
        $this->saveCustomer();
        $this->dispatch('checkout-form-submitted')->to(Checkout::class);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

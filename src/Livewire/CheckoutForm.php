<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
        $this->initializeForm();
    }

    #[Computed(persist: true)]
    public function checkoutForm()
    {
        return $this->reservation->getCheckoutForm()->toArray();
    }

    public function initializeForm()
    {
        $this->form = collect($this->checkoutForm)->mapWithKeys(function ($field) {
            // Default to an empty string or an empty array based on the field type
            $value = $field['type'] === 'checkboxes' ? [] : '';
            // If the field is in the customer form, prepopulate that value
            if ($this->reservation->customer && $this->reservation->customer->has($field['handle'])) {
                $value = $this->reservation->customer->get($field['handle']);
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

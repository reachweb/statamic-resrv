<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\CartItemData;
use Reach\StatamicResrv\Models\Availability;

class CartItemResults extends Component
{
    use Traits\HandlesStatamicQueries, Traits\HandlesAvailabilityQueries;

    #[Locked]
    public string $cartItemId;
    
    #[Locked]
    public CartItemData $data;
    
    public function mount()
    {
        //$this->checkAvailability();
    }

    #[Computed(persist: true)]
    public function entry()
    {
        return $this->getEntry($this->data->entryId);
    }

    public function updatedAvailability(): void
    {
        //$this->checkAvailability();
    }

    public function checkAvailability(): void
    {
        try {
            $results = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray(), $this->data->entryId);
            
            // If not available, invalidate the item
            if (isset($results['message']) && isset($results['message']['status']) && $results['message']['status'] === false) {
                $this->data->valid = false;
                $this->data->results = $results;
                $this->dispatch('invalidate-cart-item', itemId: $this->data->id);
                return;
            }
            $this->data->valid = true;
            $this->data->results = $results;
            $this->dispatch('update-cart-item', itemId: $this->data->id, results: $this->data->results);
            
        } catch (\Exception $e) {
            $this->data->valid = false;
            $this->data->results = [];
            $this->dispatch('invalidate-cart-item', itemId: $this->data->id);
        }
    }

    public function removeFromCart(): void
    {
        $this->dispatch('remove-from-cart', itemId: $this->data->id);
    }

    public function availabilityArray()
    {
        return [
            'date_start' => $this->data->availabilityData['dates']['date_start'],
            'date_end' => $this->data->availabilityData['dates']['date_end'],
            'quantity' => $this->data->availabilityData['quantity'],
            'advanced' => $this->data->availabilityData['advanced'],
        ];
    }

    public function render()
    {
        return view('statamic-resrv::livewire.cart-item-results');
    }
}

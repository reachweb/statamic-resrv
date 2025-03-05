<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityCartData;
use Reach\StatamicResrv\Livewire\Forms\CartItemData;

class AvailabilityCart extends Component
{
    use Traits\HandlesAvailabilityQueries,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    #[Session('resrv-cart')]
    public AvailabilityCartData $cart;

    #[On('add-to-cart')]
    public function addToCart(string $entryId, array $availabilityData, array $results): void
    {
        // Check if a similar item already exists in the cart
        $alreadyExists = $this->cart->items->first(function ($item) use ($entryId, $availabilityData) {
            // Check if entry ID is the same
            if ($item->entryId !== $entryId) {
                return false;
            }

            // Filter out quantity from both arrays and compare directly
            $existingData = array_diff_key($item->availabilityData, ['quantity' => '']);
            $newData = array_diff_key($availabilityData, ['quantity' => '']);

            if ($existingData !== $newData) {
                return false;
            }

            return true;
        });

        if ($alreadyExists !== null) {
            // Update instead of adding a new item
            $this->updateCartItem($this->buildCartItemData($entryId, $availabilityData, $results, $alreadyExists->id));

            return;
        }

        // Add the new item
        $this->cart->addItem($this->buildCartItemData($entryId, $availabilityData, $results));

        $this->dispatch('cart-updated', count: $this->cart->count());
        $this->dispatch('item-added-to-cart');
    }

    #[On('remove-from-cart')]
    public function removeFromCart(string $itemId): void
    {
        $this->cart->removeItem($itemId);

        $this->dispatch('cart-updated', count: $this->cart->count());
        $this->dispatch('item-removed-from-cart');
    }

    #[On('update-cart-item')]
    public function updateCartItem(CartItemData $data): void
    {
        $this->cart->updateItem($data);

        $this->dispatch('cart-item-updated', itemId: $data->id);
    }

    #[On('invalidate-cart-item')]
    public function invalidateCartItem(string $itemId): void
    {
        $item = $this->cart->getItem($itemId);
        if ($item) {
            $item->valid = false;
            $this->cart->updateItem($item);
        }

        $this->dispatch('cart-item-updated', itemId: $itemId);
    }

    public function buildCartItemData(string $entryId, array $availabilityData, array $results, ?string $cartId = null): CartItemData
    {
        $item = new CartItemData($this, 'cartItem');
        if ($cartId) {
            $item->id = $cartId;
        }
        $item->entryId = $entryId;
        $item->availabilityData = $availabilityData;
        $item->results = $results;

        return $item;
    }

    public function checkout(): void
    {
        //
    }

    protected function getCheckoutEntry()
    {
        return $this->getEntryById(config('resrv-config.checkout_entry'));
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-cart', [
            'itemCount' => $this->cart->count(),
            'cartItems' => $this->cart->items,
            'allValid' => $this->cart->allItemsValid(),
        ]);
    }
}

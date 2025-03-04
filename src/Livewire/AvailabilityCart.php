<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityCartData;
use Reach\StatamicResrv\Livewire\Forms\CartItemData;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Reservation;

class AvailabilityCart extends Component
{
    use Traits\HandlesAvailabilityQueries, Traits\HandlesStatamicQueries;

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
        $this->cart->updateItem($itemId, ['valid' => false]);

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
        try {
            if (! $this->cart->allItemsValid()) {
                session()->flash('error', 'Some items in your cart are not available. Please remove them or modify your selection.');

                return;
            }

            if ($this->cart->isEmpty()) {
                session()->flash('error', 'Your cart is empty.');

                return;
            }

            // Create the parent reservation
            $parentReservation = DB::transaction(function () {
                $parent = Reservation::create([
                    'item_id' => $this->cart->items->first()->entryId,
                    'reference' => (new Reservation)->createRandomReference(),
                    'status' => 'pending',
                    'type' => 'parent',
                    'quantity' => $this->cart->items->first()->availability->quantity,
                    'price' => $this->calculateTotalPrice(),
                    'payment' => $this->calculatePaymentAmount(),
                    'total' => $this->calculateTotalPrice(),
                ]);

                // Create child reservations for each cart item
                foreach ($this->cart->items as $item) {
                    $availability = new Availability;

                    // Validate availability before proceeding
                    $availabilityData = $item->availability->toResrvArray();
                    if (! $availability->confirmAvailability($availabilityData, $item->entryId)) {
                        throw new ReservationException('Item is no longer available.');
                    }

                    // Get pricing
                    $pricing = $availability->getPricing($availabilityData, $item->entryId);

                    // Create reservation
                    $reservation = new Reservation;
                    $reservation->item_id = $item->entryId;
                    $reservation->date_start = $availabilityData['date_start'];
                    $reservation->date_end = $availabilityData['date_end'];
                    $reservation->quantity = $availabilityData['quantity'];
                    $reservation->property = $availabilityData['advanced'];
                    $reservation->reference = $parent->reference;
                    $reservation->status = 'pending';
                    $reservation->price = $pricing['price'];
                    $reservation->payment = $pricing['payment'];
                    $reservation->total = $pricing['price'];

                    // Save and link to parent
                    $reservation->save();
                    $parent->childs()->create([
                        'reservation_id' => $parent->id,
                        'child_reservation_id' => $reservation->id,
                        'item_id' => $item->entryId,
                        'date_start' => $availabilityData['date_start'],
                        'date_end' => $availabilityData['date_end'],
                        'quantity' => $availabilityData['quantity'],
                        'property' => $availabilityData['advanced'],
                    ]);

                    // Decrement availability
                    $availability->decrementAvailability(
                        $reservation->date_start,
                        $reservation->date_end,
                        $reservation->quantity,
                        $reservation->item_id,
                        $reservation->id,
                        $reservation->property
                    );
                }

                return $parent;
            });

            // Store reservation ID in session for checkout
            session(['resrv_reservation' => $parentReservation->id]);

            // Clear cart after successful checkout
            $this->cart->clear();

            // Redirect to checkout page
            $this->redirect($this->getCheckoutEntry()->url());

        } catch (ReservationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    protected function getCheckoutEntry()
    {
        return $this->getEntryById(config('resrv-config.checkout_entry'));
    }

    protected function calculateTotalPrice()
    {
        $total = 0;

        foreach ($this->cart->items as $item) {
            if (isset($item->results['data']['price'])) {
                $total += (float) str_replace(',', '', $item->results['data']['price']);
            }
        }

        return number_format($total, 2, '.', '');
    }

    protected function calculatePaymentAmount()
    {
        $total = 0;

        foreach ($this->cart->items as $item) {
            if (isset($item->results['data']['payment'])) {
                $total += (float) str_replace(',', '', $item->results['data']['payment']);
            }
        }

        return number_format($total, 2, '.', '');
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

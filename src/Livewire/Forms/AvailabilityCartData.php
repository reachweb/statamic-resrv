<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

class AvailabilityCartData extends Form
{
    public Collection $items;

    public function __construct()
    {
        $this->items = collect();
    }

    public function addItem(CartItemData $item): void
    {
        $this->items->put($item->id, $item);
    }

    public function removeItem(string $id): void
    {
        $this->items->forget($id);
    }

    public function updateItem(CartItemData $item): void
    {
        if ($this->items->has($item->id)) {
            $this->items->put($item->id, $item);
        }
    }
    
    public function getItem(string $id): ?object
    {
        return $this->items->get($id);
    }

    public function clear(): void
    {
        $this->items = collect();
    }

    public function count(): int
    {
        return $this->items->count();
    }
    
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }
    
    public function hasValidItems(): bool
    {
        return $this->items->filter(fn ($item) => $item->valid)->isNotEmpty();
    }
    
    public function allItemsValid(): bool
    {
        return $this->items->every(fn ($item) => $item->valid);
    }
}

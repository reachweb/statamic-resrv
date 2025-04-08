<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Livewire\Traits\HandlesExtrasQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesOptionsQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;

class CheckoutExtrasOptions extends Component
{
    use HandlesExtrasQueries,
        HandlesOptionsQueries,
        HandlesStatamicQueries;

    public string $view = 'checkout-extras-options';

    public EnabledExtras $enabledExtras;

    public EnabledOptions $enabledOptions;

    public Collection $extraConditions;

    public Reservation $reservation;

    public string $entryId;

    public $searchData;
    public $extraSelections = [];

    public function mount()
    {
        if (session()->has('resrv-extras')) {
            $this->enabledExtras->fill(session('resrv-extras'));
        } else {
            $this->enabledExtras->extras = collect();
        }
        if (session()->has('resrv-options')) {
            $this->enabledOptions->fill(session('resrv-options'));
        } else {
            $this->enabledOptions->options = collect();
        }

        $this->extraConditions = collect();
        $this->updateExtraConditions();
    }

    protected function initExtraSelections()
    {
        $this->extraSelections = [];
        
        // Check existing selections from enabledExtras
        if ($this->enabledExtras->extras && $this->enabledExtras->extras->count() > 0) {
            foreach ($this->enabledExtras->extras as $extra) {
                $this->extraSelections[$extra['id']] = true;
            }
        }
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        return $this->getExtrasForReservation();
    }

    #[Computed(persist: true)]
    public function frontendExtras(): Collection
    {
        return $this->extras->groupBy('category_id')
            ->sortBy('order')
            ->map(function ($items) {
                return $this->createExtraCategoryObject($items);
            })
            ->reject(function ($category) {
                return $category->published == false;
            })
            ->sortBy('order')
            ->values();
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        return $this->getOptionsForReservation();
    }

    #[Computed]
    public function hiddenExtras(): Collection
    {
        //ray($this->extraConditions->get('hide'))->label('Hidden extras');
        return $this->extraConditions->get('hide');
    }

    public function toggleExtra($extraId, $price, $quantity = 1)
    {
        $extraId = (int)$extraId;

        $isSelected = $this->isExtraSelected($extraId);

        if ($isSelected) {
            $this->enabledExtras->extras->forget($extraId);
        } else {
            $this->enabledExtras->extras->put($extraId, [
                'id' => $extraId,
                'price' => $price,
                'quantity' => $quantity
            ]);
        }

        $this->updateExtraConditions();
        
        $this->dispatchExtrasUpdated();
    }

    public function updateExtraQuantity($extraId, $quantity)
    {
        $extraId = (int) $extraId;
        $quantity = (int) $quantity;

        // If the extra is not selected, return
        if (!$this->isExtraSelected($extraId)) {
            return;
        }
        
        // Get the extra from the collection directly by key
        $extra = $this->enabledExtras->extras->get($extraId);
        $originalExtra = $this->extras->firstWhere('id', $extraId);

        if ($quantity > 0 && $quantity > $originalExtra->minimum && $quantity < $originalExtra->maximum) {
            $extra['quantity'] = $quantity;
            $this->enabledExtras->extras->put($extraId, $extra);
        }

        $this->dispatchExtrasUpdated();

        ray($this->enabledExtras->extras)->label('Updated quantity');
    }

    public function dispatchExtrasUpdated()
    {
        $this->dispatch('extrasUpdated', $this->enabledExtras->extras);
    }

    public function updateOption($optionId, $valueId, $price)
    {
        $optionId = (int)$optionId;
        $valueId = (int)$valueId;
        
        // Check if the option exists in the collection
        $existingIndex = $this->enabledOptions->options->search(function ($item) use ($optionId) {
            return $item['id'] === $optionId;
        });

        if ($existingIndex !== false) {
            // Update the option value
            $option = $this->enabledOptions->options[$existingIndex];
            $option['value'] = $valueId;
            $option['price'] = $price;
            $this->enabledOptions->options[$existingIndex] = $option;
        } else {
            // Add the option
            $this->enabledOptions->options->push([
                'id' => $optionId,
                'value' => $valueId,
                'price' => $price
            ]);
        }

        $this->dispatch('optionsUpdated', $this->enabledOptions->options);
    }

    public function isExtraSelected($extraId)
    {
        return $this->enabledExtras->extras->has((int)$extraId);
    }

    public function getSelectedExtraQuantity($extraId)
    {
        $extra = $this->enabledExtras->extras->firstWhere('id', (int)$extraId);
        return $extra ? $extra['quantity'] : 1;
    }

    public function getSelectedOptionValue($optionId)
    {
        $option = $this->enabledOptions->options->firstWhere('id', (int)$optionId);
        return $option ? $option['value'] : null;
    }

    public function isExtraVisible($extraId): bool
    {
        return $this->extraConditions->get('hide')->contains((int) $extraId) === false;
    }

    public function setExtraRequired($extraId, $required)
    {
        $extraId = (int)$extraId;
        
        if ($required) {
            $this->dispatchBrowserEvent('set-extra-required', [
                'id' => $extraId,
                'required' => true
            ]);
        } else {
            $this->dispatchBrowserEvent('set-extra-required', [
                'id' => $extraId,
                'required' => false
            ]);
        }
    }

    public function setExtraHidden($extraId, $hidden)
    {
        $extraId = (int)$extraId;
        
        if ($hidden) {
            $this->dispatchBrowserEvent('set-extra-hidden', [
                'id' => $extraId,
                'hidden' => true
            ]);
        } else {
            $this->dispatchBrowserEvent('set-extra-hidden', [
                'id' => $extraId,
                'hidden' => false
            ]);
        }
    }

    public function updateExtraConditions()
    {
        $this->handleExtrasConditions($this->extras);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

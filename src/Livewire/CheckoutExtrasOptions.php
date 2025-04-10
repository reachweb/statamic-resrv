<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Livewire\Traits\HandlesExtrasQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesOptionsQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

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

    #[Reactive]
    public ?array $optionsErrors = null;

    #[Reactive]
    public ?array $extrasErrors = null;

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

        $this->extraConditions = collect([
            'hide' => collect(),
            'required' => collect(),
        ]);
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
        return $this->extraConditions->get('hide');
    }

    #[Computed]
    public function requiredExtras(): Collection
    {
        return $this->extraConditions->get('required');
    }

    public function toggleExtra($extraId)
    {
        $extraId = (int) $extraId;

        $isSelected = $this->isExtraSelected($extraId);

        if ($isSelected) {
            $this->enabledExtras->extras->forget($extraId);
        } else {
            $extra = $this->extras->firstWhere('id', $extraId);
            $this->enabledExtras->extras->put($extraId, [
                'id' => $extraId,
                'price' => $extra->price->format(),
                'name' => $extra->name,
                'quantity' => 1,
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
        if (! $this->isExtraSelected($extraId)) {
            return;
        }

        $extra = $this->enabledExtras->extras->get($extraId);
        $originalExtra = $this->extras->firstWhere('id', $extraId);

        if ($quantity > 0 && ($originalExtra->maximum == 0 || $quantity <= $originalExtra->maximum)) {
            $extra['quantity'] = $quantity;
            $this->enabledExtras->extras->put($extraId, $extra);
        }

        $this->dispatchExtrasUpdated();
    }

    public function dispatchExtrasUpdated()
    {
        $this->dispatch('extras-updated', $this->enabledExtras->extras);
    }

    public function selectOption($optionId, $valueId)
    {
        $optionId = (int) $optionId;
        $valueId = (int) $valueId;

        $option = $this->options->firstWhere('id', $optionId);
        $value = $option->values->firstWhere('id', $valueId);

        // Create option data
        $option = [
            'id' => $optionId,
            'value' => $valueId,
            'price' => $value->price->format(),
            'optionName' => $option->name,
            'valueName' => $value->name,
        ];

        // Save the option with its ID as the key
        $this->enabledOptions->options->put($optionId, $option);

        $this->dispatch('options-updated', $this->enabledOptions->options);
    }

    public function isExtraSelected($extraId)
    {
        return $this->enabledExtras->extras->has((int) $extraId);
    }

    public function getExtraQuantity($extraId)
    {
        $extra = $this->enabledExtras->extras->get((int) $extraId);

        return $extra ? $extra['quantity'] : 1;
    }

    public function getSelectedOptionValue($optionId)
    {
        $option = $this->enabledOptions->options->get((int) $optionId);

        return $option ? $option['value'] : null;
    }

    public function updateExtraConditions()
    {
        $this->handleExtrasConditions($this->extras);
    }

    #[On('extra-conditions-changed')]
    public function handleExtrasConditionChange($old)
    {
        // Disable any enabled extras that got hidden
        if ($this->hiddenExtras->count() > 0) {
            $this->hiddenExtras->each(function ($extraId) {
                if ($this->isExtraSelected($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
        }

        $oldRequired = collect($old['required']);

        if ($this->conditionsHaveChanged($this->requiredExtras, $oldRequired)) {
            // Enable new required extras
            $this->requiredExtras->each(function ($extraId) {
                if (! $this->isExtraSelected($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
            // Disable old required extras
            $oldRequired->each(function ($extraId) {
                if ($this->isExtraSelected($extraId) && ! $this->requiredExtras->contains($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
        }
    }

    #[On('extras-coupon-changed')]
    public function updateOnCoupon(): void
    {
        // Clear the cache
        unset($this->extras);
        unset($this->frontendExtras);

        if ($this->enabledExtras->extras->count() !== 0) {
            $this->updateEnabledExtraPrices();
            $this->dispatchExtrasUpdated();
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

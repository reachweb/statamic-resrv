<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Traits\HandlesExtrasQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class Extras extends Component
{
    use HandlesExtrasQueries,
        HandlesStatamicQueries;

    public string $view = 'extras';

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Locked]
    public Collection $extraConditions;

    #[Locked]
    public Reservation $reservation;

    #[Locked]
    public AvailabilityData $data;

    #[Locked]
    public ?string $entryId = null;

    #[Locked]
    public $filter = false;

    #[Reactive]
    public ?array $errors = null;

    public function mount()
    {
        if (! isset($this->reservation) && ! $this->entryId) {
            throw new \Exception('Entry ID is required when reservation is not provided');
        }

        if (session()->has('resrv-extras')) {
            $this->enabledExtras->fill(session('resrv-extras'));
            $this->dispatchExtrasUpdated();
        } else {
            $this->enabledExtras->extras = collect();
        }

        $this->extraConditions = collect([
            'hide' => collect(),
            'required' => collect(),
        ]);
        $this->updateExtraConditions();
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        $extras = isset($this->reservation)
            ? $this->getExtrasForReservation()
            : $this->getExtrasForSearch($this->data->toResrvArray(), $this->entryId);

        if (is_string($this->filter)) {
            $extrasToShow = explode('|', $this->filter);

            return $extras->filter(function ($extra) use ($extrasToShow) {
                return in_array($extra->id, $extrasToShow);
            });
        }

        return $extras;
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
                'name' => $extra->override_label ?? $extra->name,
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

    public function isExtraSelected($extraId)
    {
        return $this->enabledExtras->extras->has((int) $extraId);
    }

    public function getExtraQuantity($extraId)
    {
        $extra = $this->enabledExtras->extras->get((int) $extraId);

        return $extra ? $extra['quantity'] : 1;
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

    #[On('extras-coupon-changed'), On('availability-search-updated')]
    public function updateOnChange(): void
    {
        $this->data = session('resrv-search');

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

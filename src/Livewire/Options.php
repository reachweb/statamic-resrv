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
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Livewire\Traits\HandlesOptionsQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class Options extends Component
{
    use HandlesOptionsQueries,
        HandlesStatamicQueries;

    public string $view = 'options';

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public Reservation $reservation;

    #[Locked]
    public AvailabilityData $data;

    #[Locked]
    public ?string $entryId = null;

    #[Locked]
    public $filter = false;

    /**
     * Opt-in flag for the multi-cart pricing path. Multi-results pages set
     * this to true so the Options component aggregates prices across the
     * in-progress cart selections instead of the live search payload.
     * Standard availability-results pages must leave it false, otherwise an
     * unrelated cart for the same entry would hijack their pricing.
     */
    #[Locked]
    public bool $useMultiSelections = false;

    #[Reactive]
    public ?array $errors = null;

    public function mount()
    {
        if (! isset($this->reservation) && ! $this->entryId) {
            throw new \Exception('Entry ID is required when reservation is not provided');
        }

        if (session()->has('resrv-options')) {
            $this->enabledOptions->fill(session('resrv-options'));
            $this->dispatchOptionsUpdated();
        } else {
            $this->enabledOptions->options = collect();
        }
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        $multiSelections = isset($this->reservation) ? null : $this->getMultiSelectionsFromSessionForOptions();

        if ($multiSelections !== null) {
            $options = $this->getOptionsForSelections($multiSelections, $this->entryId);
        } else {
            $options = isset($this->reservation)
                ? $this->getOptionsForReservation()
                : $this->getOptionsForSearch($this->data->toResrvArray(), $this->entryId);
        }

        if (is_string($this->filter)) {
            $optionsToShow = explode('|', $this->filter);

            return $options->filter(function ($option) use ($optionsToShow) {
                return in_array($option->id, $optionsToShow);
            });
        }

        return $options;
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

        $this->dispatchOptionsUpdated();
    }

    public function dispatchOptionsUpdated()
    {
        $this->dispatch('options-updated', $this->enabledOptions->options);
    }

    public function isOptionValueSelected($optionId, $valueId)
    {
        return $this->enabledOptions->options->has((int) $optionId) &&
            $this->enabledOptions->options->get((int) $optionId)['value'] === (int) $valueId;
    }

    #[On('availability-search-updated')]
    public function updateOnChange(): void
    {
        $this->data = session('resrv-search');
        // Clear the cache
        unset($this->options);
    }

    #[On('multi-selections-updated')]
    public function refreshOnSelectionsUpdate(): void
    {
        // The cart's selections changed, so any aggregated option prices computed
        // from them are now stale. Force a recompute on next render.
        unset($this->options);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

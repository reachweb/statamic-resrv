<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    use Traits\HandlesStatamicQueries;

    public string $view = 'availability-search';

    #[Session('resrv-search')]
    public AvailabilityData $data;

    public string $calendar = 'single';

    public bool $live = true;

    public $advanced = false;

    public bool $anyAdvanced = false;

    public bool $enableQuantity = false;

    public ?string $redirectTo = null;

    public array $overrideProperties;

    #[Computed(persist: true)]
    public function advancedProperties(): array
    {
        if (! $this->advanced) {
            return [];
        }

        return $this->overrideProperties ?? $this->getProperties();
    }

    #[Computed(persist: true)]
    public function maxQuantity(): int
    {
        return config('resrv-config.maximum_quantity');
    }

    public function updatedData(): void
    {
        if ($this->live && $this->validateDatesAreSet()) {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->data->validate();

        if ($this->anyAdvanced) {
            $this->data->advanced = 'any';
        }

        $this->dispatch('availability-search-updated', $this->data);

        if ($this->redirectTo && ! $this->live) {
            redirect($this->redirectTo);
        }
    }

    public function submit(): void
    {
        $this->search();
    }

    public function validateDatesAreSet(): bool
    {
        $datesAreSet = isset($this->data->dates['date_start']) && isset($this->data->dates['date_end']);

        if (! $datesAreSet) {
            $this->addError('data.dates.date_start', 'Availability search requires date information to be provided.');
        }

        return $datesAreSet;
    }

    public function clearDates(): void
    {
        $this->data->reset();
        // Apparently validation errors don't reset with the above
        $this->resetValidation();

        $this->dispatch('availability-search-updated', $this->data);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    #[Session('resrv-search')]
    public AvailabilityData $data;

    public string $calendar = 'single';

    public bool $live = true;

    public bool $advanced = false;

    public array $advancedProperties;

    #[Computed(persist: true)]
    public function advancedProperties(): array
    {
        return $advancedProperties ?? $this->getProperties();
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
        $this->dispatch('availability-search-updated', $this->data);
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
        $this->data->reset('dates');

        // Apparently validation errors don't reset with the above
        $this->resetValidation('data.dates.date_start');
        $this->resetValidation('data.dates.date_end');
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-search');
    }
}

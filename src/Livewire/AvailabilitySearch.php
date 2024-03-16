<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public string $calendar = 'single';

    #[Locked]
    public bool $advanced = false;

    public function updatedData(): void
    {
        $this->data->validate();

        $this->dispatch('availability-search-updated', $this->data);
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

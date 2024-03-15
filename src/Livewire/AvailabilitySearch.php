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
    public string $mode = 'single';

    public function updatedData(): void
    {
        $this->data->validate();

        $this->dispatch('availability-search-updated', $this->data);
    }

    public function clear()
    {
        $this->data->reset();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-search');
    }
}

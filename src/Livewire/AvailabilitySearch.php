<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    public AvailabilityData $data;

    public function updatedData()
    {
        $this->data->validate();

        $this->dispatch('availability-search-updated', $this->data);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-search');
    }
}

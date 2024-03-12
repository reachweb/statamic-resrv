<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    public AvailabilityData $data;

    public function mount(): void
    {
        if (session()->has('resrv-search')) {
            $this->data->fillFromSession();
            $this->updatedData();
        }
    }

    public function updatedData(): void
    {
        $this->data->validate();

        session()->put('resrv-search', $this->data->toArray());

        $this->dispatch('availability-search-updated', [
            'date_start' => $this->data->dates['date_start'],
            'date_end' => $this->data->dates['date_end'],
            'quantity' => $this->data->quantity,
            'property' => $this->data->property,
        ]
        );
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-search');
    }
}

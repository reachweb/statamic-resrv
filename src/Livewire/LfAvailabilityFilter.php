<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection;
use Reach\StatamicLivewireFilters\Http\Livewire\Traits\IsLivewireFilter;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class LfAvailabilityFilter extends Component
{
    use IsLivewireFilter;

    public AvailabilityData $data;

    #[Locked]
    public string $calendar = 'single';

    #[Locked]
    public bool $advanced = false;

    #[Locked]
    public bool $enableQuantity = false;

    #[Locked]
    public bool $live = true;

    #[Computed(persist: true)]
    public function enableAdvanced()
    {
        if ($this->advanced === true) {
            return $this->collection.'.'.$this->blueprint;
        }

        return false;
    }

    #[On('availability-search-updated')]
    public function availabilityChanged($data)
    {
        $this->data->fill($data);
        $this->dispatch('filter-updated',
            field: $this->field,
            condition: $this->condition,
            payload: $this->data,
            command: 'replace',
            modifier: $this->modifier,
        )
            ->to(LivewireCollection::class);
    }

    #[On('availability-search-cleared')]
    public function clear()
    {
        $this->clearFilters();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.filters.lf-availability');
    }
}

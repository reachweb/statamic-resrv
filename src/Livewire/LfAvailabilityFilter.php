<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection;
use Reach\StatamicLivewireFilters\Http\Livewire\Traits\IsLivewireFilter;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class LfAvailabilityFilter extends Component
{
    use IsLivewireFilter;

    public $selected = '';

    public AvailabilityData $data;

    #[Locked]
    public string $mode = 'single';

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

    public function clear()
    {
        $this->selected = '';
        $this->clearFilters();
    }

    // #[On('preset-params')]
    // public function setPresetSort($params)
    // {
    //     if (array_key_exists($this->getParamKey(), $params)) {
    //         $this->selected = $params[$this->getParamKey()];
    //     }
    // }

    public function render()
    {
        return view('statamic-resrv::livewire.filters.lf-availability');
    }
}

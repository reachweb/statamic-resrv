<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicLivewireFilters\Exceptions\FieldNotFoundException;
use Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection;
use Reach\StatamicLivewireFilters\Http\Livewire\Traits\IsLivewireFilter;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class LfAvailabilityFilter extends Component
{
    use IsLivewireFilter;

    public AvailabilityData $data;

    #[Locked]
    public string $calendar = 'single';

    #[Locked]
    public bool $rates = false;

    #[Locked]
    public bool $enableQuantity = false;

    #[Locked]
    public bool $live = true;

    #[Computed(persist: true)]
    public function enableRates(): string|false
    {
        return $this->rates ? $this->collection.'.'.$this->blueprint : false;
    }

    public function initiateField(): void
    {
        $blueprint = $this->getStatamicBlueprint();
        $field = AvailabilityField::getField($blueprint);

        if (! $field) {
            throw new FieldNotFoundException('resrv_availability', $this->blueprint);
        }

        $this->statamic_field = $field->toArray();
        $this->field = $field->handle();
        $this->condition = 'query_scope';
        $this->modifier = 'resrv_search';
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

        $this->dispatch('availability-results-updated');
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

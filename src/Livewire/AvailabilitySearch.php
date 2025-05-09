<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilitySearch extends Component
{
    use Traits\HandlesAvailabilityQueries, Traits\HandlesStatamicQueries;

    public string $view = 'availability-search';

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public string $calendar = 'single';

    #[Locked]
    public bool $live = true;

    #[Locked]
    public $advanced = false;

    #[Locked]
    public bool $anyAdvanced = false;

    #[Locked]
    public bool $resetAdvancedOnBoot = false;

    #[Locked]
    public bool $enableQuantity = false;

    #[Locked]
    public ?string $redirectTo = null;

    #[Locked]
    public ?string $entry = null;

    #[Locked]
    public bool $showAvailabilityOnCalendar = false;

    #[Locked]
    public array $overrideProperties = [];

    public function boot(): void
    {
        if ($this->resetAdvancedOnBoot && $this->data->advanced !== null) {
            $this->data->advanced = null;
            $this->search();
        }
    }

    #[Computed(persist: true)]
    public function advancedProperties(): array
    {
        if (! $this->advanced) {
            return [];
        }

        return count($this->overrideProperties) > 0 ? $this->overrideProperties : $this->getProperties();
    }

    #[Computed(persist: true)]
    public function maxQuantity(): int
    {
        return config('resrv-config.maximum_quantity');
    }

    public function availabilityCalendar(): array
    {
        if ($this->showAvailabilityOnCalendar === false) {
            return [];
        }

        return $this->getAvailabilityCalendar();
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

        if ($this->data->advanced == null && $this->anyAdvanced) {
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

        if (! $datesAreSet && ! $this->anyAdvanced) {
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

        if (! $this->live) {
            $this->dispatch('availability-results-updated');
            if ($this->redirectTo) {
                redirect($this->redirectTo);
            }
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

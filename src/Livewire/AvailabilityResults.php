<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry;
use Statamic\Support\Traits\Hookable;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesCutoffValidation,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'availability-results';

    #[Locked]
    public string $entryId;

    #[Locked]
    public Collection $availability;

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public int $extraDays = 0;

    #[Locked]
    public int $extraDaysOffset = 0;

    #[Locked]
    public $advanced = false;

    #[Locked]
    public $showExtras = false;

    #[Locked]
    public $showOptions = false;

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public array $overrideProperties = [];

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
        $this->enabledExtras->extras = collect();
        $this->enabledOptions->options = collect();
        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }

        $this->runHooks('init');
    }

    #[Computed(persist: true)]
    public function entry(): ?Entry
    {
        return $this->getEntry($this->entryId) ?? null;
    }

    #[Computed(persist: true)]
    public function advancedProperties(): array
    {
        if (! $this->advanced) {
            return [];
        }

        return count($this->overrideProperties) > 0 ? $this->overrideProperties : $this->getEntryProperties($this->entry);
    }

    #[On('availability-search-updated')]
    public function availabilitySearchChanged($data): void
    {
        // Clear availability so that we don't get a view error
        $this->availability = collect();

        // Fill the data
        $this->data->fill($data);

        // Validate again in case the session data is old
        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->getAvailability();

        $this->runHooks('availability-results-updated', $this->availability);

        $this->dispatch('availability-results-updated');
    }

    public function getAvailability(): void
    {
        // Order is important here so that the cutoff validation works correctly
        if ($this->extraDays > 0) {
            $this->availability = $this->queryExtraAvailabilityForEntry();

            return;
        }

        // Validate cutoff rules
        try {
            $this->validateCutoffRules();
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('cutoff', $exception->getMessage());

            return;
        }

        if ($this->advanced === true) {
            $this->data->advanced = 'any';
            $this->availability = collect($this->queryAvailabilityForAllProperties());

            return;
        }

        if ($this->extraDays === 0) {
            $this->availability = collect($this->queryBaseAvailabilityForEntry());

            return;
        }

    }

    public function checkout(): void
    {
        if ($this->extraDays !== 0 && $this->availability->count() > 1) {
            $this->availability = collect($this->availability->get(0));
        }
        if ($this->data->advanced === 'any') {
            $this->data->advanced = data_get($this->availability, 'data.property');
        }
        try {
            $this->validateAvailabilityAndPrice();
            $this->createReservation();

            $this->redirect($this->getCheckoutEntry()->url());
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    public function checkoutProperty(string $property): void
    {
        $this->data->advanced = $property;
        $this->availability = collect($this->availability->get($property));
        $this->checkout();
    }

    #[On('extras-updated')]
    public function updateExtras($extras): void
    {
        $this->enabledExtras->extras = collect($extras);
    }

    #[On('options-updated')]
    public function updateOptions($options): void
    {
        $this->enabledOptions->options = collect($options);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

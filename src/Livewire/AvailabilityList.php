<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry;
use Statamic\Support\Traits\Hookable;

class AvailabilityList extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'availability-list';

    #[Locked]
    public string $entryId;

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public bool $rates = false;

    #[Locked]
    public array $overrideRates = [];

    #[Locked]
    public bool $groupByDate = false;

    #[Locked]
    public Collection $availableDates;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availableDates = collect();

        // Use the date-first view when groupByDate is enabled
        if ($this->groupByDate && $this->view === 'availability-list') {
            $this->view = 'availability-list-by-date';
        }

        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }

        $this->runHooks('init');
    }

    #[Computed(persist: true)]
    public function entry(): ?Entry
    {
        return $this->getEntry($this->entryId);
    }

    #[Computed(persist: true)]
    public function entryRates(): array
    {
        if (! $this->rates) {
            return [];
        }

        if ($this->overrideRates) {
            return $this->overrideRates;
        }

        $rates = $this->getRatesForEntry($this->entryId);

        if ($rates->isNotEmpty()) {
            return $rates->mapWithKeys(fn ($rate) => [$rate->id => $rate->title])->toArray();
        }

        // Fallback to blueprint properties for backward compatibility
        return $this->getEntryProperties($this->entry);
    }

    #[On('availability-search-updated')]
    public function availabilitySearchChanged($data): void
    {
        $this->availableDates = collect();

        $this->data->fill($data);

        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->getAvailableDates();

        $this->runHooks('availability-results-updated', $this->availableDates);

        $this->dispatch('availability-results-updated');
    }

    protected function getAvailableDates(): void
    {
        $this->availableDates = collect($this->queryAvailableDatesFromDate());
    }

    public function selectDate(string $date, ?string $rateId = null): void
    {
        $this->dispatch('availability-date-selected', [
            'date' => $date,
            'rate_id' => $rateId,
        ]);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

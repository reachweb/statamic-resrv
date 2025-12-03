<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry;
use Statamic\Support\Traits\Hookable;

class AvailabilityList extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesStatamicQueries;

    public string $view = 'availability-list';

    #[Locked]
    public string $entryId;

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public $advanced = false;

    #[Locked]
    public array $overrideProperties = [];

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
        $this->availableDates = collect();

        $this->data->fill($data);

        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->dispatch('availability-list-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->getAvailableDates();

        $this->runHooks('availability-list-updated', $this->availableDates);

        $this->dispatch('availability-list-updated');
    }

    protected function getAvailableDates(): void
    {
        $dates = $this->queryAvailableDatesFromDate();

        $this->availableDates = collect($dates);
    }

    protected function queryAvailableDatesFromDate(): array
    {
        if (! isset($this->data->dates['date_start'])) {
            return [];
        }

        $dateStart = Carbon::parse($this->data->dates['date_start'])->format('Y-m-d');

        $advanced = $this->data->advanced ? [$this->data->advanced] : ($this->advanced ? ['any'] : null);

        return app(Availability::class)->getAvailableDatesFromDate(
            $this->entryId,
            $dateStart,
            $this->data->quantity ?? 1,
            $advanced,
            $this->groupByDate
        );
    }

    public function selectDate(string $date, ?string $property = null): void
    {
        $this->dispatch('availability-date-selected', [
            'date' => $date,
            'property' => $property,
        ]);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

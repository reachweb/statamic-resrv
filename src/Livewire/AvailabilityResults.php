<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds, Traits\QueriesAvailability;

    public string $entryId;

    public Collection $availability;

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public int $extraDays = 0;

    #[Locked]
    public int $extraDaysOffset = 0;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }
    }

    #[On('availability-search-updated')]
    public function availabilitySearchChanged($data)
    {
        $this->data->fill($data);
        $this->getAvailability();
    }

    public function getAvailability(): void
    {
        if ($this->extraDays === 0) {
            $this->availability = collect($this->queryBaseAvailabilityForEntry());

            return;
        }
        if ($this->extraDays > 0) {
            $this->availability = $this->queryExtraAvailabilityForEntry();

            return;
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-results');
    }
}

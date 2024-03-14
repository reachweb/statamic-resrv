<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds, Traits\QueriesAvailability;

    public string $entryId;

    public Collection $availability;

    #[Locked]
    public int $extraDays = 0;

    #[Locked]
    public int $extraDaysOffset = 0;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
    }

    #[On('availability-search-updated')]
    public function getAvailability($data): void
    {
        if ($this->extraDays === 0) {
            $this->availability = collect($this->queryBaseAvailability($data));

            return;
        }
        if ($this->extraDays > 0) {
            $this->availability = $this->queryExtraAvailability($data);

            return;
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-results');
    }
}

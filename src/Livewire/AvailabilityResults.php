<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Component;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds;

    public string $entryId;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-results');
    }
}

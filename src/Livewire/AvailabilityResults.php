<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds;

    public string $entryId;

    public array $availability = [];

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
    }

    #[On('availability-search-updated')]
    public function getAvailabilityForItem($data)
    {
        try {
            $this->availability = (new Availability)->getAvailabilityForItem($data, $this->entryId);
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.availability-results');
    }
}

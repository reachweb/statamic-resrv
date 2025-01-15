<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;

class AvailabilityControl extends Component
{
    #[Session('resrv-search')]
    public AvailabilityData $data;

    public function save(): void
    {
        $this->data->validate();

        $this->dispatch('availability-search-updated', $this->data);
    }

    public function render()
    {
        return <<<'HTML'
        <div></div>
        HTML;
    }
}

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

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesExtrasQueries,
        Traits\HandlesOptionsQueries,
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
    public $showExtras = false;

    #[Locked]
    public $showOptions = false;

    public EnabledExtras $enabledExtras;

    public EnabledOptions $enabledOptions;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        if ($this->showExtras) {
            $extras = $this->getExtrasForSearch($this->data->toResrvArray(), $this->entryId);
            if ($this->showExtras === true) {
                return $extras;
            }
            else {
                $extrasToShow = explode('|', $this->showExtras);
                return $extras->filter(function ($extra) use ($extrasToShow) {
                    return in_array($extra->id, $extrasToShow);
                });
            }
            
        }

        return collect();
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        if ($this->showOptions) {
            $options = $this->getOptionsForSearch($this->data->toResrvArray(), $this->entryId);
            if ($this->showOptions === true) {
                return $options;
            }
            else {
                $optionsToShow = explode('|', $this->showOptions);
                return $options->filter(function ($option) use ($optionsToShow) {
                    return in_array($option->id, $optionsToShow);
                });
            }
        }

        return collect();
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
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');

            return;
        }
        $this->getAvailability();

        $this->dispatch('availability-results-updated');
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

    public function checkout(): void
    {
        try {
            $this->validateAvailabilityAndPrice();
            $this->createReservation();

            $this->redirect($this->getCheckoutEntry()->url());
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

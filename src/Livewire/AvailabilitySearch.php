<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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
    public bool $rates = false;

    #[Locked]
    public ?string $ratesBlueprint = null;

    #[Locked]
    public bool $anyRate = false;

    #[Locked]
    public bool $enableQuantity = false;

    #[Locked]
    public ?string $redirectTo = null;

    #[Locked]
    public ?string $entry = null;

    #[Locked]
    public bool $showAvailabilityOnCalendar = false;

    #[Locked]
    public array $calendarRules = [];

    /**
     * Developer-supplied rate options that bypass resolution from the Rate model.
     * MUST be an id-keyed map [rate_id => label]; a bare list breaks the auto-select
     * in AvailabilityData::reconcileRate() (a list renders option value="0").
     *
     * @var array<int|string, string>
     */
    #[Locked]
    public array $overrideRates = [];

    public function mount(): void
    {
        $this->reconcileRateForContext();
    }

    #[Computed(persist: true)]
    public function entryRates(): array
    {
        return $this->computeEntryRates($this->entry);
    }

    #[Computed(persist: true)]
    public function maxQuantity(): int
    {
        return config('resrv-config.maximum_quantity');
    }

    public function availabilityCalendar(): array
    {
        if (! $this->showAvailabilityOnCalendar) {
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

    public function search(?bool $withoutValidation = false): void
    {
        if (! $withoutValidation) {
            $this->data->validate();
        }

        $this->reconcileRateForContext();

        if (! $this->data->rate && $this->anyRate) {
            $this->data->rate = 'any';
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
        if (! $this->data->hasDates() && ! $this->anyRate) {
            $this->addError('data.dates.date_start', 'Availability search requires date information to be provided.');
        }

        return $this->data->hasDates();
    }

    public function clearDates(): void
    {
        $this->data->reset();
        // Apparently validation errors don't reset with the above
        $this->resetValidation();
        // Re-heal / auto-select after the reset wiped the rate, before dispatch.
        $this->reconcileRateForContext();

        $this->dispatch('availability-search-updated', $this->data);

        if (! $this->live) {
            $this->dispatch('availability-results-updated');
            if (! $this->redirectTo) {
                $this->js('window.location.reload()');
            }
        }
    }

    /**
     * Heal only when we can judge the rate: an entry-scoped search validates/auto-selects
     * against that entry's rates; a rates-disabled search clears any carried value. A
     * context-less rate search bar (entry === null, rates === true) cannot judge, so it
     * leaves the value for the receiving List/Collection/Results to reconcile — and must NOT
     * drop a rate just set by availabilityDateSelected() from a sibling date grid.
     */
    protected function reconcileRateForContext(): void
    {
        if ($this->entry || ! $this->rates) {
            $this->data->reconcileRate($this->entryRates, $this->rates);
        }
    }

    #[On('availability-date-selected')]
    public function availabilityDateSelected(array $data): void
    {
        if (! isset($data['date']) || ! is_string($data['date'])) {
            return;
        }

        try {
            $dateStart = Carbon::parse($data['date']);
        } catch (InvalidFormatException $e) {
            return;
        }

        $minimumPeriod = max(1, config('resrv-config.minimum_reservation_period_in_days', 1));

        $this->data->dates['date_start'] = $dateStart->toDateString();
        $this->data->dates['date_end'] = $dateStart->copy()->addDays($minimumPeriod)->toDateString();

        if (isset($data['rate_id'])) {
            $this->data->rate = $data['rate_id'];
        }

        $this->search();
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

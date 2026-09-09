<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Livewire\Traits\HandlesOptionsQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class Options extends Component
{
    use HandlesOptionsQueries,
        HandlesStatamicQueries;

    public string $view = 'options';

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public Reservation $reservation;

    #[Locked]
    public AvailabilityData $data;

    #[Locked]
    public ?string $entryId = null;

    #[Locked]
    public $filter = false;

    /**
     * Opt-in flag for the multi-cart pricing path. Multi-results pages set
     * this to true so the Options component aggregates prices across the
     * in-progress cart selections instead of the live search payload.
     * Standard availability-results pages must leave it false, otherwise an
     * unrelated cart for the same entry would hijack their pricing.
     */
    #[Locked]
    public bool $useMultiSelections = false;

    #[Reactive]
    public ?array $errors = null;

    /**
     * The rate the parent results component resolved for the current search (see
     * AvailabilityResults::effectiveRateId()). A rates-disabled search carries no rate, but the
     * booking will, and option value prices depend on it (units_per_addon). Set at mount by the
     * results view and refreshed by the 'availability-results-updated' event, so a customised
     * view that omits it still converges. Ignored when pricing a reservation or a multi-results
     * cart, which carry their own rate ids.
     */
    #[Locked]
    public ?int $effectiveRateId = null;

    public function mount()
    {
        if (! isset($this->reservation) && ! $this->entryId) {
            throw new \Exception('Entry ID is required when reservation is not provided');
        }

        if (session()->has('resrv-options')) {
            $this->enabledOptions->fill(session('resrv-options'));
            // Several components persist this shared session key on every Livewire
            // dehydrate, so the stored prices can be a stale snapshot (e.g. computed
            // for a previous search or cart state). Keep the selection but re-derive
            // the prices for the current context before broadcasting them.
            if ($this->canPriceSelection()) {
                $this->updateEnabledOptionPrices();
            }
            $this->dispatchOptionsUpdated();
        } else {
            $this->enabledOptions->options = collect();
        }
    }

    #[Computed(persist: true)]
    public function options(): Collection
    {
        $multiSelections = isset($this->reservation) ? null : $this->getMultiSelectionsFromSessionForOptions();

        if ($multiSelections !== null) {
            $options = $this->getOptionsForSelections($multiSelections, $this->entryId);
        } else {
            $options = isset($this->reservation)
                ? $this->getOptionsForReservation()
                : $this->getOptionsForSearch($this->searchDataForPricing(), $this->entryId);
        }

        if (is_string($this->filter)) {
            $optionsToShow = explode('|', $this->filter);

            return $options->filter(function ($option) use ($optionsToShow) {
                return in_array($option->id, $optionsToShow);
            });
        }

        return $options;
    }

    public function selectOption($optionId, $valueId)
    {
        $optionId = (int) $optionId;
        $valueId = (int) $valueId;

        $option = $this->options->firstWhere('id', $optionId);

        // selectOption is a public, client-callable action — ignore ids not in the available list
        if (! $option) {
            return;
        }

        $value = $option->values->firstWhere('id', $valueId);

        if (! $value) {
            return;
        }

        // Create option data
        $option = [
            'id' => $optionId,
            'value' => $valueId,
            'price' => $value->price->format(),
            'optionName' => $option->name,
            'valueName' => $value->name,
        ];

        // Save the option with its ID as the key
        $this->enabledOptions->options->put($optionId, $option);

        $this->dispatchOptionsUpdated();
    }

    public function dispatchOptionsUpdated()
    {
        $this->dispatch('options-updated', $this->enabledOptions->options);
    }

    public function isOptionValueSelected($optionId, $valueId)
    {
        return $this->enabledOptions->options->has((int) $optionId) &&
            $this->enabledOptions->options->get((int) $optionId)['value'] === (int) $valueId;
    }

    #[On('availability-search-updated')]
    public function updateOnChange(): void
    {
        $this->data = session('resrv-search');
        // Clear the cache
        unset($this->options);

        // Keep the last derived prices when the new search cannot be priced (see
        // canPriceSelection()); the next real search re-prices them.
        if (! $this->canPriceSelection()) {
            return;
        }

        // The selection stores a price; re-derive it for the new search (dates, quantity, rate)
        // and broadcast it, or the parent's totals and the checkout keep the stale amount.
        $this->repriceSelection();
    }

    /**
     * Whether the current context can price the selection. A reservation and a cart carry their
     * own dates; a search needs some: pricing a dateless one (the calendar's clear button, a
     * page rendered before any search) would store per-day values at 0.00 (duration 0) in the
     * shared session, and the checkout would trust that amount.
     */
    protected function canPriceSelection(): bool
    {
        return isset($this->reservation)
            || $this->getMultiSelectionsFromSessionForOptions() !== null
            || $this->data->hasDates();
    }

    /**
     * The results component resolved the rate the current search will book. Re-price the list
     * and the selection when it differs from the one in use; a search change already re-priced
     * under the previous rate, so an unchanged rate is a no-op. Payload-less dispatches (failed
     * searches, other results components) are ignored.
     */
    #[On('availability-results-updated')]
    public function updateEffectiveRate($rateId = null): void
    {
        if (isset($this->reservation) || $this->getMultiSelectionsFromSessionForOptions() !== null) {
            return;
        }

        $rateId = is_numeric($rateId) ? (int) $rateId : null;

        if ($rateId === null || $rateId === $this->effectiveRateId) {
            return;
        }

        $this->effectiveRateId = $rateId;

        unset($this->options);

        $this->repriceSelection();
    }

    protected function repriceSelection(): void
    {
        if ($this->enabledOptions->options->count() === 0) {
            return;
        }

        $this->updateEnabledOptionPrices();
        $this->dispatchOptionsUpdated();
    }

    /**
     * The search payload option values are priced with: the search data, with the resolved rate
     * standing in when the search carries none ('any' or null).
     */
    protected function searchDataForPricing(): array
    {
        $data = $this->data->toResrvArray();

        if (! is_numeric($data['rate_id'] ?? null) && $this->effectiveRateId) {
            $data['rate_id'] = $this->effectiveRateId;
        }

        return $data;
    }

    #[On('multi-selections-updated')]
    public function refreshOnSelectionsUpdate(): void
    {
        // The cart's selections changed, so any aggregated option prices computed
        // from them are now stale. Force a recompute on next render.
        unset($this->options);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

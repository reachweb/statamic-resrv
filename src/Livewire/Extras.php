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
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Traits\HandlesExtrasQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class Extras extends Component
{
    use HandlesExtrasQueries,
        HandlesStatamicQueries;

    public string $view = 'extras';

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Locked]
    public Collection $extraConditions;

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
     * this to true so the Extras component aggregates prices/conditions across
     * the in-progress cart selections instead of the live search payload.
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
     * booking will, and add-on prices depend on it (units_per_addon, relative extras). Set at
     * mount by the results view and refreshed by the 'availability-results-updated' event, so a
     * customised view that omits it still converges. Ignored when pricing a reservation or a
     * multi-results cart, which carry their own rate ids.
     */
    #[Locked]
    public ?int $effectiveRateId = null;

    public function mount()
    {
        if (! isset($this->reservation) && ! $this->entryId) {
            throw new \Exception('Entry ID is required when reservation is not provided');
        }

        $this->extraConditions = collect([
            'hide' => collect(),
            'required' => collect(),
        ]);

        if (session()->has('resrv-extras')) {
            $this->enabledExtras->fill(session('resrv-extras'));
            // Several components persist this shared session key on every Livewire
            // dehydrate, so the stored prices can be a stale snapshot (e.g. computed
            // for a previous search or cart state). Keep the selection but re-derive
            // the prices for the current context before broadcasting them.
            if ($this->canPriceSelection()) {
                $this->updateEnabledExtraPrices();
            }
            $this->dispatchExtrasUpdated();
        } else {
            $this->enabledExtras->extras = collect();
        }

        $this->updateExtraConditions();
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        $multiSelections = isset($this->reservation) ? null : $this->getMultiSelectionsFromSession();

        if ($multiSelections !== null) {
            $extras = $this->getExtrasForSelections($multiSelections, $this->entryId);
        } else {
            $extras = isset($this->reservation)
                ? $this->getExtrasForReservation()
                : $this->getExtrasForSearch($this->searchDataForPricing(), $this->entryId);
        }

        if (is_string($this->filter)) {
            $extrasToShow = explode('|', $this->filter);

            return $extras->filter(function ($extra) use ($extrasToShow) {
                return in_array($extra->id, $extrasToShow);
            });
        }

        return $extras;
    }

    #[Computed(persist: true)]
    public function frontendExtras(): Collection
    {
        return $this->extras->groupBy('category_id')
            ->sortBy('order')
            ->map(function ($items) {
                return $this->createExtraCategoryObject($items);
            })
            ->reject(function ($category) {
                return $category->published == false;
            })
            ->sortBy('order')
            ->values();
    }

    #[Computed]
    public function hiddenExtras(): Collection
    {
        return $this->extraConditions->get('hide');
    }

    #[Computed]
    public function requiredExtras(): Collection
    {
        return $this->extraConditions->get('required');
    }

    public function toggleExtra($extraId)
    {
        $extraId = (int) $extraId;

        $isSelected = $this->isExtraSelected($extraId);

        if ($isSelected) {
            $this->enabledExtras->extras->forget($extraId);
        } else {
            $extra = $this->extras->firstWhere('id', $extraId);

            // toggleExtra is a public, client-callable action — ignore ids not in the available list
            if (! $extra) {
                return;
            }

            $this->enabledExtras->extras->put($extraId, [
                'id' => $extraId,
                'price' => $extra->price->format(),
                'name' => $extra->override_label ?? $extra->name,
                'quantity' => 1,
            ]);
        }

        $this->updateExtraConditions();

        $this->dispatchExtrasUpdated();
    }

    public function updateExtraQuantity($extraId, $quantity)
    {
        $extraId = (int) $extraId;
        $quantity = (int) $quantity;

        // If the extra is not selected, return
        if (! $this->isExtraSelected($extraId)) {
            return;
        }

        $extra = $this->enabledExtras->extras->get($extraId);
        $originalExtra = $this->extras->firstWhere('id', $extraId);

        if ($quantity > 0 && ($originalExtra->maximum == 0 || $quantity <= $originalExtra->maximum)) {
            $extra['quantity'] = $quantity;
            $this->enabledExtras->extras->put($extraId, $extra);
        }

        $this->dispatchExtrasUpdated();
    }

    public function dispatchExtrasUpdated()
    {
        $this->dispatch('extras-updated', $this->enabledExtras->extras);
    }

    public function isExtraSelected($extraId)
    {
        return $this->enabledExtras->extras->has((int) $extraId);
    }

    public function getExtraQuantity($extraId)
    {
        $extra = $this->enabledExtras->extras->get((int) $extraId);

        return $extra ? $extra['quantity'] : 1;
    }

    public function updateExtraConditions()
    {
        $this->handleExtrasConditions($this->extras);
    }

    #[On('extra-conditions-changed')]
    public function handleExtrasConditionChange($old)
    {
        // Disable any enabled extras that got hidden
        if ($this->hiddenExtras->count() > 0) {
            $this->hiddenExtras->each(function ($extraId) {
                if ($this->isExtraSelected($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
        }

        $oldRequired = collect($old['required']);

        if ($this->conditionsHaveChanged($this->requiredExtras, $oldRequired)) {
            // Enable new required extras
            $this->requiredExtras->each(function ($extraId) {
                if (! $this->isExtraSelected($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
            // Disable old required extras
            $oldRequired->each(function ($extraId) {
                if ($this->isExtraSelected($extraId) && ! $this->requiredExtras->contains($extraId)) {
                    $this->toggleExtra($extraId);
                }
            });
        }
    }

    #[On('extras-coupon-changed'), On('availability-search-updated')]
    public function updateOnChange(): void
    {
        $this->data = session('resrv-search');

        // Clear the cache
        unset($this->extras);
        unset($this->frontendExtras);

        // Keep the last derived prices when the new search cannot be priced (see
        // canPriceSelection()); the next real search re-prices them. A reservation prices from
        // its own dates, so a coupon change on the checkout page always re-prices.
        if (! $this->canPriceSelection()) {
            return;
        }

        if ($this->enabledExtras->extras->count() !== 0) {
            $this->updateEnabledExtraPrices();
            $this->dispatchExtrasUpdated();
        }
    }

    /**
     * Whether the current context can price the selection. A reservation and a cart carry their
     * own dates; a search needs some: pricing a dateless one (the calendar's clear button, a
     * page rendered before any search) would store per-day extras at 0.00 (duration 0) in the
     * shared session, and the checkout would trust that amount.
     */
    protected function canPriceSelection(): bool
    {
        return isset($this->reservation)
            || $this->getMultiSelectionsFromSession() !== null
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
        if (isset($this->reservation) || $this->getMultiSelectionsFromSession() !== null) {
            return;
        }

        $rateId = is_numeric($rateId) ? (int) $rateId : null;

        if ($rateId === null || $rateId === $this->effectiveRateId) {
            return;
        }

        $this->effectiveRateId = $rateId;

        unset($this->extras);
        unset($this->frontendExtras);

        if ($this->enabledExtras->extras->count() !== 0) {
            $this->updateEnabledExtraPrices();
            $this->dispatchExtrasUpdated();
        }
    }

    /**
     * The search payload extras are priced with: the search data, with the resolved rate
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
        // The cart's selections changed, so any aggregated extra prices/conditions
        // computed from them are now stale. Force a recompute on next render.
        unset($this->extras);
        unset($this->frontendExtras);

        $this->updateExtraConditions();

        if ($this->enabledExtras->extras->count() !== 0) {
            $this->dispatchExtrasUpdated();
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

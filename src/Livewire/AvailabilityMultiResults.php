<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry;
use Statamic\Support\Traits\Hookable;

class AvailabilityMultiResults extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesCutoffValidation,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'availability-multi-results';

    #[Locked]
    public string $entryId;

    #[Locked]
    public Collection $availability;

    #[Session('resrv-search')]
    public AvailabilityData $data;

    #[Locked]
    public bool $rates = true;

    #[Locked]
    public $showExtras = false;

    #[Locked]
    public $showOptions = false;

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public array $overrideRates = [];

    /** @var array<string, int> Map of rate_id => quantity for current date range */
    public array $rateQuantities = [];

    /** @var array<int, array{date_start: string, date_end: string, rate_id: int, quantity: int, price: string, rate_label: string}> */
    #[Session('resrv-multi-selections')]
    public array $selections = [];

    /**
     * Tracks which entry the persisted cart belongs to. Stored in session
     * separately from the cart so we can detect entry switches in mount(),
     * and so child components (Extras, Options) can scope their use of
     * multi-cart selections to the entry that actually owns the cart.
     */
    public const CART_OWNER_SESSION_KEY = 'resrv-multi-cart-entry';

    public function mount(string $entry): void
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();

        // Session-backed selections/extras/options are scoped to the entry
        // that owns the cart. Wipe stale add-on state when the persisted cart
        // belongs to a *different* entry, or to none at all (because the
        // previous cart was already converted into a reservation by checkout()
        // — checkout() intentionally leaves resrv-extras/resrv-options behind
        // so the Checkout component can copy them onto the reservation, and
        // we are the ones cleaning them up on the next multi-results visit).
        $cartOwner = session(self::CART_OWNER_SESSION_KEY);
        if ($cartOwner !== $this->entryId) {
            $this->selections = [];
            $this->enabledExtras->extras = collect();
            $this->enabledOptions->options = collect();
            if ($cartOwner !== null) {
                session()->forget(self::CART_OWNER_SESSION_KEY);
            }
        }

        // Form object Collection properties are typed and uninitialized when
        // the Session attribute didn't restore them (e.g., a fresh visit with
        // an empty session). Backfill them so downstream code can iterate safely.
        if (! isset($this->enabledExtras->extras)) {
            $this->enabledExtras->extras = collect();
        }
        if (! isset($this->enabledOptions->options)) {
            $this->enabledOptions->options = collect();
        }

        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }

        $this->runHooks('init');
    }

    #[Computed(persist: true)]
    public function entry(): ?Entry
    {
        return $this->getEntry($this->entryId);
    }

    #[Computed(persist: true)]
    public function entryRates(): array
    {
        return $this->computeEntryRates($this->entryId);
    }

    #[On('availability-search-updated')]
    public function availabilitySearchChanged($data): void
    {
        $this->availability = collect();
        $this->rateQuantities = [];

        $this->data->fill($data);

        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->getAvailability();

        $this->runHooks('availability-results-updated', $this->availability);

        $this->dispatch('availability-results-updated');
    }

    public function getAvailability(): void
    {
        try {
            $this->validateCutoffRules();
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('cutoff', $exception->getMessage());

            return;
        }

        // Always query at quantity=1 so the cart UI shows per-unit prices.
        // The user picks per-rate quantities afterwards via updateRateQuantity(),
        // and validateMultiAvailabilityAndPrice() re-validates with the real totals.
        $this->availability = collect($this->queryAvailabilityForAllRates(quantityOverride: 1));
    }

    public function updateRateQuantity(int $rateId, int $quantity): void
    {
        $this->rateQuantities[$rateId] = max(0, $quantity);
    }

    public function addSelections(): void
    {
        if (! $this->data->hasDates()) {
            $this->addError('availability', __('Please select dates first.'));

            return;
        }

        $added = false;

        foreach ($this->rateQuantities as $rateId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $rateData = $this->availability->get($rateId);

            if (! $rateData || data_get($rateData, 'message.status') !== true) {
                continue;
            }

            $this->selections[] = [
                'date_start' => $this->data->dates['date_start'],
                'date_end' => $this->data->dates['date_end'],
                'rate_id' => $rateId,
                'quantity' => $quantity,
                'price' => data_get($rateData, 'data.price'),
                'rate_label' => $this->entryRates[$rateId] ?? 'Rate',
            ];

            $added = true;
        }

        if (! $added) {
            $this->addError('availability', __('Please select at least one rate with a quantity.'));

            return;
        }

        $this->rateQuantities = [];
        $this->markCartOwner();
        $this->recalculateAddonPricing();
        $this->dispatch('multi-selections-updated');
    }

    public function removeSelection(int $index): void
    {
        unset($this->selections[$index]);
        $this->selections = array_values($this->selections);

        if (empty($this->selections)) {
            $this->forgetCartOwner();
        }

        $this->recalculateAddonPricing();
        $this->dispatch('multi-selections-updated');
    }

    public function clearSelections(): void
    {
        $this->selections = [];
        $this->forgetCartOwner();
        $this->recalculateAddonPricing();
        $this->dispatch('multi-selections-updated');
    }

    protected function markCartOwner(): void
    {
        session()->put(self::CART_OWNER_SESSION_KEY, $this->entryId);
    }

    protected function forgetCartOwner(): void
    {
        session()->forget(self::CART_OWNER_SESSION_KEY);
    }

    #[Computed]
    public function totalPrice(): string
    {
        if (empty($this->selections)) {
            return '0.00';
        }

        $total = Price::create(0);

        foreach ($this->selections as $selection) {
            $total->add(Price::create($this->lineTotal($selection)));
        }

        $total->add($this->calculateExtraTotals());
        $total->add($this->calculateOptionTotals());

        return $total->format();
    }

    /**
     * Compute the displayed line total for a single cart selection. Selections
     * store per-unit prices (queried at quantity=1), so we multiply here unless
     * the project has opted out of quantity-based pricing.
     *
     * @param  array{price: string, quantity: int}  $selection
     */
    public function lineTotal(array $selection): string
    {
        $price = Price::create($selection['price']);

        if ($selection['quantity'] > 1 && ! config('resrv-config.ignore_quantity_for_prices', false)) {
            $price->multiply((string) $selection['quantity']);
        }

        return $price->format();
    }

    #[Computed]
    public function totalQuantity(): int
    {
        return array_sum(array_column($this->selections, 'quantity'));
    }

    public function checkout(): void
    {
        if (empty($this->selections)) {
            $this->addError('availability', __('Please select at least one rate.'));

            return;
        }

        try {
            $selections = collect($this->selections);
            $this->validateMultiAvailabilityAndPrice($selections);
            $this->createMultiReservation($selections);

            // The cart has been converted to a reservation. Drop the
            // selections + cart owner so the next multi-results visit starts
            // empty, but leave resrv-extras/resrv-options in session so the
            // Checkout component (specifically when enableExtrasStep === false)
            // can copy them onto the reservation. mount() of the next visit to
            // any multi-results page wipes the stale add-on state, since the
            // cart owner no longer matches.
            $this->selections = [];
            $this->forgetCartOwner();

            $this->redirect($this->getCheckoutEntry()->url());
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    #[On('extras-updated')]
    public function updateExtras($extras): void
    {
        $this->enabledExtras->extras = collect($extras);
        $this->recalculateExtrasPricing();
    }

    #[On('options-updated')]
    public function updateOptions($options): void
    {
        $this->enabledOptions->options = collect($options);
        $this->recalculateOptionsPricing();
    }

    protected function buildSelectionDataArrays(): array
    {
        return array_map(fn ($selection) => [
            'date_start' => $selection['date_start'],
            'date_end' => $selection['date_end'],
            'quantity' => $selection['quantity'],
            'item_id' => $this->entryId,
            'rate_id' => $selection['rate_id'],
        ], $this->selections);
    }

    protected function recalculateAddonPricing(): void
    {
        if (empty($this->selections)) {
            $this->enabledExtras->extras = collect();
            $this->enabledOptions->options = collect();

            return;
        }

        $this->recalculateExtrasPricing();
        $this->recalculateOptionsPricing();
    }

    protected function recalculateExtrasPricing(): void
    {
        if ($this->enabledExtras->extras->isEmpty() || empty($this->selections)) {
            return;
        }

        $selectionDataArrays = $this->buildSelectionDataArrays();

        $this->enabledExtras->extras = $this->enabledExtras->extras->map(function ($extra) use ($selectionDataArrays) {
            $totalPrice = Price::create(0);

            foreach ($selectionDataArrays as $selectionData) {
                $totalPrice->add(Price::create(Extra::find($extra['id'])->priceForDates($selectionData)));
            }

            $extra['price'] = $totalPrice->format();

            return $extra;
        });
    }

    protected function recalculateOptionsPricing(): void
    {
        if ($this->enabledOptions->options->isEmpty() || empty($this->selections)) {
            return;
        }

        $selectionDataArrays = $this->buildSelectionDataArrays();

        $this->enabledOptions->options = $this->enabledOptions->options->map(function ($option) use ($selectionDataArrays) {
            $totalPrice = Price::create(0);

            foreach ($selectionDataArrays as $selectionData) {
                $totalPrice->add(Price::create(OptionValue::find($option['value'])->priceForDates($selectionData)));
            }

            $option['price'] = $totalPrice->format();

            return $option;
        });
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

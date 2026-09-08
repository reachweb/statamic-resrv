<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Enums\RateSorting;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry;
use Statamic\Support\Traits\Hookable;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesCutoffValidation,
        Traits\HandlesPricing,
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
    public bool $rates = false;

    #[Locked]
    public string $rateSorting = 'order';

    #[Locked]
    public $showExtras = false;

    #[Locked]
    public $showOptions = false;

    #[Session('resrv-extras')]
    public EnabledExtras $enabledExtras;

    #[Session('resrv-options')]
    public EnabledOptions $enabledOptions;

    /**
     * Developer-supplied rate options that bypass resolution from the Rate model.
     * MUST be an id-keyed map [rate_id => label]; a bare list breaks the auto-select
     * in AvailabilityData::reconcileRate() (a list renders option value="0").
     *
     * @var array<int|string, string>
     */
    #[Locked]
    public array $overrideRates = [];

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
        $this->enabledExtras->extras = collect();
        $this->enabledOptions->options = collect();
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

        $this->data->fill($data);
        $this->data->reconcileRate($this->entryRates, $this->rates);

        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->loadAvailability();

        $this->runHooks('availability-results-updated', $this->availability);

        // Tell the Extras/Options children which rate this search will book (see
        // effectiveRateId()); with rates disabled the search itself carries none.
        $this->dispatch('availability-results-updated', rateId: $this->effectiveRateId());
    }

    /**
     * The rate the availability engine resolved for the current search, i.e. the rate a
     * reservation created from these results gets. With rates disabled (the default) the search
     * rate is null while the engine still books a concrete rate, and add-on pricing depends on
     * it (units_per_addon, relative extras), so the Extras/Options children price against this
     * instead of the search rate. Null while results are empty or an all-rates listing is shown.
     */
    public function effectiveRateId(): ?int
    {
        $result = $this->extraDays > 0 ? $this->availability->get(0) : $this->availability;

        if (data_get($result, 'message.status') !== true) {
            return null;
        }

        $rateId = data_get($result, 'data.rate_id');

        return is_numeric($rateId) ? (int) $rateId : null;
    }

    public function loadAvailability(): void
    {
        if ($this->extraDays > 0) {
            $this->availability = $this->queryExtraAvailabilityForEntry();

            return;
        }

        try {
            $this->validateCutoffRules();
        } catch (\Exception $exception) {
            $this->dispatch('availability-results-updated');
            $this->addError('cutoff', $exception->getMessage());

            return;
        }

        if ($this->rates) {
            if (! $this->data->rate || $this->data->rate === 'any') {
                $this->data->rate = 'any';
                $this->availability = collect($this->queryAvailabilityForAllRates());
            } else {
                $this->availability = collect($this->queryBaseAvailabilityForEntry());
            }

            return;
        }

        $this->availability = collect($this->queryBaseAvailabilityForEntry());
    }

    protected function resolveRateSorting(): RateSorting
    {
        return RateSorting::fromValue($this->rateSorting);
    }

    public function checkout(): void
    {
        if ($this->extraDays !== 0 && $this->availability->count() > 1) {
            $this->availability = collect($this->availability->get(0));
        }
        if (! $this->data->rate || $this->data->rate === 'any') {
            if ($this->rates && $this->availability->count() > 1) {
                $this->addError('availability', __('Please select a rate before proceeding.'));

                return;
            }
            $rateFromResults = data_get($this->availability, 'data.rate_id');
            if ($rateFromResults) {
                $this->data->rate = (string) $rateFromResults;
            }
        }

        try {
            // Pricing the add-ons validates the search too (quantity bounds), so it belongs
            // with the other AvailabilityException sources.
            $this->repriceEnabledAddons();
            $this->validateAvailabilityAndPrice();
            $this->createReservation();

            $this->redirect($this->getCheckoutEntry()->url());
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    /**
     * Book one rate straight from the all-rates listing. The Extras/Options children are not
     * rendered there, so the selection they priced earlier (under another rate, or none) is
     * carried over as is; checkout() re-prices it under the rate being booked.
     */
    public function checkoutRate(string $rateId): void
    {
        $this->data->rate = $rateId;
        $this->availability = collect($this->availability->get($rateId));
        $this->checkout();
    }

    /**
     * Re-derive the selected add-ons' prices for the booking that is about to be made: the
     * search's dates and quantity, and the rate it settled on. The children price the selection
     * as it is made and on every search change, but they may have priced it under another rate
     * (a rate card on the all-rates listing, see checkoutRate()) or without the resolved one (a
     * customised view that omits effectiveRateId, before the next search corrects it). The
     * session-backed selection is what a checkout without an extras step trusts, so it has to
     * carry the amounts the reservation will be validated against. A selection whose extra or
     * value no longer exists keeps its stored price; Reservation::validateExtraCharges() rejects
     * it at the checkout with an explicit extras/options error.
     */
    protected function repriceEnabledAddons(): void
    {
        $data = array_merge($this->data->toResrvArray(), ['item_id' => $this->entryId]);

        if ($this->enabledExtras->extras->isNotEmpty()) {
            $extras = Extra::findMany($this->enabledExtras->extras->pluck('id'))->keyBy('id');

            $this->enabledExtras->extras = $this->enabledExtras->extras->map(function ($extra) use ($extras, $data) {
                if ($model = $extras->get($extra['id'])) {
                    $extra['price'] = $model->priceForDates($data);
                }

                return $extra;
            });
        }

        if ($this->enabledOptions->options->isNotEmpty()) {
            $values = OptionValue::findMany($this->enabledOptions->options->pluck('value'))->keyBy('id');

            $this->enabledOptions->options = $this->enabledOptions->options->map(function ($option) use ($values, $data) {
                if ($value = $values->get($option['value'])) {
                    $option['price'] = $value->priceForDates($data);
                }

                return $option;
            });
        }
    }

    #[On('extras-updated')]
    public function updateExtras($extras): void
    {
        $this->enabledExtras->extras = collect($extras);
    }

    #[On('options-updated')]
    public function updateOptions($options): void
    {
        $this->enabledOptions->options = collect($options);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

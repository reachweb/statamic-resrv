<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\CutoffException;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityData;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\EntryCollection;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Traits\Hookable;

class AvailabilityCollection extends Component
{
    use HandlesMultisiteIds,
        Hookable,
        Traits\HandlesAvailabilityQueries,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries,
        WithPagination;

    public string $view = 'availability-collection';

    #[Locked]
    public ?string $collection = null;

    #[Locked]
    public array $entries = [];

    #[Locked]
    public bool $rates = false;

    #[Locked]
    public bool $showRates = false;

    #[Locked]
    public bool $showUnavailable = false;

    #[Locked]
    public ?int $paginate = null;

    #[Locked]
    public string $sort = 'order';

    #[Locked]
    public array $overrideRates = [];

    #[Session('resrv-search')]
    public AvailabilityData $data;

    // False when the last search failed validation, so rows() skips querying with
    // invalid dates (hasDates() only checks key presence, not validity).
    #[Locked]
    public bool $searchIsValid = true;

    // Set only by select()'s direct-booking branch, for the reused single-entry traits.
    #[Locked]
    public string $entryId = '';

    #[Locked]
    public Collection $availability;

    public function mount(): void
    {
        if (! $this->collection && empty($this->entries)) {
            throw new \InvalidArgumentException('The availability-collection component requires a "collection" handle or an "entries" array.');
        }

        $this->availability = collect();

        if (session()->has('resrv-search')) {
            $this->availabilitySearchChanged(session('resrv-search'));
        }

        $this->runHooks('init');
    }

    #[On('availability-search-updated')]
    public function availabilitySearchChanged($data): void
    {
        $this->resetPage();

        $this->data->fill($data);

        try {
            $this->data->validate();
            $this->runHooks('availability-search-updated', $this->data);
        } catch (\Exception $exception) {
            $this->searchIsValid = false;
            $this->dispatch('availability-results-updated');
            $this->addError('availability', $exception->getMessage());

            return;
        }

        $this->searchIsValid = true;

        // Bust the memoized computeds so the next access re-queries.
        unset($this->resolvedEntries, $this->rows);

        $this->runHooks('availability-results-updated', $this->rows);

        $this->dispatch('availability-results-updated');
    }

    #[Computed]
    public function resolvedEntries(): EntryCollection|LengthAwarePaginator
    {
        $query = Entry::query();

        if ($this->collection) {
            $query->where('collection', $this->collection);
        }

        if (! empty($this->entries)) {
            // Match either the passed ids or the origin they localize: on a non-default
            // site the configured ids are the default-site origins, but the localizations
            // we want carry their own ids. Grouped so the filters below still AND against it.
            $query->where(function ($query) {
                $query->whereIn('id', $this->entries)
                    ->orWhereIn('origin', $this->entries);
            });
        }

        if (Site::hasMultiple()) {
            $query->where('site', Site::current()->handle());
        }

        // whereStatus (not where('published', true)) so dated collections exclude
        // private scheduled/expired entries that are still published === true.
        $query->whereStatus('published');

        if ($this->sort === 'title') {
            $query->orderBy('title');
        } elseif ($this->sort === 'order' && $this->collection) {
            // Honour the collection's configured order, as Statamic's {{ collection }} tag
            // does; without an explicit orderBy the Stache returns raw storage order.
            if ($collection = \Statamic\Facades\Collection::findByHandle($this->collection)) {
                $query->orderBy($collection->sortField(), $collection->sortDirection());
            }
        }

        return $this->paginate
            ? $query->paginate($this->paginate)
            : $query->get();
    }

    /**
     * One row per entry, each carrying the entry plus a `live_availability`-compatible
     * payload (a "from" row and the available rate rows). Availability for the whole
     * (possibly paginated) set is fetched in a single batched query.
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->searchIsValid || ! $this->data->hasDates()) {
            return collect();
        }

        $entries = $this->resolvedEntries;

        $items = $entries instanceof LengthAwarePaginator
            ? collect($entries->items())
            : $entries;

        if ($items->isEmpty()) {
            return collect();
        }

        $response = $this->getAvailability($this->searchPayload(), $entries);

        // An availability-level rejection (a range the model rejects but the form rules
        // allow) returns message.error with no data — surface it rather than show "no
        // availability".
        if ($error = data_get($response, 'message.error')) {
            if (! $this->getErrorBag()->has('availability')) {
                $this->addError('availability', $error);
            }

            return collect();
        }

        $availability = data_get($response, 'data');

        $rows = $items->map(function ($entry) use ($availability) {
            // Availability is keyed by the origin id in multisite.
            $lookupId = $entry->hasOrigin() ? $entry->origin()->id() : $entry->id();
            $rates = $availability instanceof Collection ? $availability->get($lookupId) : null;

            return [
                'id' => $entry->id(),
                'entry' => $entry,
                'available' => $rates !== null && $rates->isNotEmpty(),
                'from' => $rates?->first(),
                'rates' => $rates ?? collect(),
            ];
        });

        if (! $this->showUnavailable) {
            $rows = $rows->filter(fn ($row) => $row['available']);
        }

        if ($this->sort === 'price') {
            $rows = $rows->sortBy(fn ($row) => $row['available'] ? (float) data_get($row['from'], 'price') : INF);
        }

        return $rows->values();
    }

    /**
     * Rate id => label map for the rates in the current rows, resolved in one query
     * (the batched availability rows carry no labels).
     */
    #[Computed]
    public function rateLabels(): array
    {
        if (! $this->rates && ! $this->showRates) {
            return [];
        }

        if ($this->overrideRates) {
            return $this->overrideRates;
        }

        $rateIds = $this->rows
            ->flatMap(fn ($row) => $row['rates']->pluck('rate_id'))
            ->filter()
            ->unique()
            ->values();

        if ($rateIds->isEmpty()) {
            return [];
        }

        return Rate::whereIn('id', $rateIds)->pluck('title', 'id')->toArray();
    }

    public function select(string $entryId, ?int $rateId = null): void
    {
        $entry = $this->getEntry($entryId);

        if (! $this->isEntryInScope($entry)) {
            $this->addError('availability', __('This item is not available.'));

            return;
        }

        if ($rateId) {
            $this->data->rate = (string) $rateId;
        }

        // If the entry has a detail page, send the visitor there — the #[Session] search
        // carries over and the single-entry components take the booking from there.
        if ($entry->url()) {
            $this->redirect($entry->url());

            return;
        }

        // No detail page: book directly via the single-entry path. queryBaseAvailabilityForEntry()
        // shapes $this->availability with the flat data.price the reservation methods expect.
        $this->entryId = $this->getDefaultSiteEntry($entryId)->id();
        $this->availability = collect($this->queryBaseAvailabilityForEntry());

        if (data_get($this->availability, 'data.price') === null) {
            if (! $this->getErrorBag()->has('availability')) {
                $this->addError('availability', __('Please select a rate before proceeding.'));
            }

            return;
        }

        // select() may be called without a rate. createReservation() reads the rate from
        // $this->data, so persist the one resolved above — otherwise rate_id saves as null
        // and the availability decrement runs unscoped. Mirrors AvailabilityResults::checkout().
        if (! $this->data->rate || $this->data->rate === 'any') {
            if ($resolvedRate = data_get($this->availability, 'data.rate_id')) {
                $this->data->rate = (string) $resolvedRate;
            }
        }

        try {
            // CutoffException does not extend AvailabilityException, so catch it explicitly.
            $this->validateCutoffRules();
            $this->validateAvailabilityAndPrice();
            $this->createReservation();

            $this->redirect($this->getCheckoutEntry()->url());
        } catch (AvailabilityException|CutoffException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    protected function searchPayload(): Collection
    {
        return collect([[
            'dates' => $this->data->dates,
            'quantity' => $this->data->quantity,
            'rate' => $this->data->rate,
        ]]);
    }

    protected function isEntryInScope($entry): bool
    {
        // select() is callable with any id, so re-apply the listing's filters here to
        // reject stale or forged ids: published-and-public, current-site, in-scope.
        if (! $entry || ! $entry->published() || $entry->private()) {
            return false;
        }

        if (Site::hasMultiple() && $entry->locale() !== Site::current()->handle()) {
            return false;
        }

        if ($this->collection && $entry->collection()->handle() !== $this->collection) {
            return false;
        }

        if (! empty($this->entries)) {
            $originId = $entry->hasOrigin() ? $entry->origin()->id() : $entry->id();

            if (! in_array($entry->id(), $this->entries, true) && ! in_array($originId, $this->entries, true)) {
                return false;
            }
        }

        return true;
    }

    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}

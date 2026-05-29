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

    // False when the last availability-search-updated payload failed validation, so
    // rows() does not query availability with invalid (e.g. unparseable) dates —
    // hasDates() only checks for key presence, not that the dates are valid.
    #[Locked]
    public bool $searchIsValid = true;

    // Transient state, set only by the per-entry checkout branch in select() so the
    // reused single-entry trait methods (queryBaseAvailabilityForEntry, createReservation)
    // have the entry/availability they expect. Never read for the listing itself.
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

        // Bust the memoized computed results so the next access re-queries.
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
            // Accept either current-site ids or origin ids. On a non-default site the
            // configured origin ids belong to the default-site entries, while the
            // localizations we actually want carry their own ids and reference the origin.
            // Group the OR so the site/published filters below still AND against it.
            $query->where(function ($query) {
                $query->whereIn('id', $this->entries)
                    ->orWhereIn('origin', $this->entries);
            });
        }

        if (Site::hasMultiple()) {
            $query->where('site', Site::current()->handle());
        }

        // whereStatus('published') (not where('published', true)) so dated collections
        // with private future/past behavior exclude scheduled/expired entries — a raw
        // published === true entry can still be private and must not be listed or booked.
        // The collection clause above must precede this per Statamic's query builder.
        $query->whereStatus('published');

        if ($this->sort === 'title') {
            $query->orderBy('title');
        }

        return $this->paginate
            ? $query->paginate($this->paginate)
            : $query->get();
    }

    /**
     * One row per entry, each carrying the resolved Statamic entry plus a
     * `live_availability`-compatible payload (a single "from" row and the full
     * set of available rate rows). Availability is fetched in a single batched
     * query for the whole (possibly paginated) set.
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

        // An availability-level rejection (e.g. a range the model rejects but the
        // form rules let through) comes back as message.error with no data key.
        // Surface it through the same channel the view renders instead of silently
        // collapsing to an empty "no availability" state.
        if ($error = data_get($response, 'message.error')) {
            if (! $this->getErrorBag()->has('availability')) {
                $this->addError('availability', $error);
            }

            return collect();
        }

        $availability = data_get($response, 'data');

        $rows = $items->map(function ($entry) use ($availability) {
            // Availability is stored against the origin entry id in multisite.
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
     * Rate id => label map for every rate surfaced in the current rows. Resolved
     * in one query (rate labels are not populated on the batched availability rows).
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

        // If the entry has its own detail page, persist the search (the #[Session]
        // attribute carries date/rate/quantity) and send the visitor there, where
        // the single-entry availability components take over.
        if ($entry->url()) {
            $this->redirect($entry->url());

            return;
        }

        // No detail page: book directly and go to checkout, reusing the exact
        // single-entry path. queryBaseAvailabilityForEntry() shapes $this->availability
        // with the flat `data.price` that validateAvailabilityAndPrice()/createReservation() expect.
        $this->entryId = $this->getDefaultSiteEntry($entryId)->id();
        $this->availability = collect($this->queryBaseAvailabilityForEntry());

        if (data_get($this->availability, 'data.price') === null) {
            if (! $this->getErrorBag()->has('availability')) {
                $this->addError('availability', __('Please select a rate before proceeding.'));
            }

            return;
        }

        // select() may be called without a rate (the argument is nullable, e.g. from a
        // custom view). queryBaseAvailabilityForEntry() above resolves a concrete rate,
        // but createReservation() reads the rate from $this->data — so persist it here,
        // otherwise the reservation saves rate_id = null and the availability decrement
        // runs unscoped across every rate row. Mirrors AvailabilityResults::checkout().
        if (! $this->data->rate || $this->data->rate === 'any') {
            if ($resolvedRate = data_get($this->availability, 'data.rate_id')) {
                $this->data->rate = (string) $resolvedRate;
            }
        }

        try {
            // CutoffException does not extend AvailabilityException, so it must be
            // caught explicitly — surfaced through the same 'availability' error
            // channel the view renders.
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
        // Mirror the listing's whereStatus('published') filter: a private (scheduled
        // or expired) entry is published === true but must not be bookable directly.
        if (! $entry || ! $entry->published() || $entry->private()) {
            return false;
        }

        // Mirror the listing's where('site', …) filter so an entry from another site —
        // one that never appeared in the current-site listing — cannot be selected via
        // a stale or forged call (the rendered rows only ever pass current-site ids).
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

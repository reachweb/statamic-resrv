<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Arr;

trait HandlesQueryStringSeeding
{
    /**
     * One-shot seed of the search from URL query parameters on the initial page
     * load (mount() never runs on subsequent Livewire requests). Supported:
     * ?date=Y-m-d (date_end derived), ?date_start=Y-m-d&date_end=Y-m-d, ?rate=<id|any>.
     * URL params override the #[Session]-restored search; invalid values are
     * silently ignored. Never redirects and never leaves validation errors.
     */
    protected function seedFromQueryString(): void
    {
        $query = request()->query();

        $rateSeeded = $this->applyRateFromQuery($query);
        $datesSeeded = $this->applyDatesFromQuery($query);

        if (! $rateSeeded && ! $datesSeeded) {
            return;
        }

        // A page can hold several search bars with different rate contexts, and an
        // earlier bar's dehydrate rewrites the shared session before a later one
        // mounts. Each bar therefore seeds its own state from the URL (a
        // rates-enabled bar must not inherit a sibling's context-reconciled copy),
        // and exactly one bar dispatches the search.
        if (request()->attributes->get('resrv-url-seed-dispatched')) {
            return;
        }

        // Nothing to search without dates (URL-seeded or carried in the session);
        // the seeded rate still preselects the control and persists via #[Session].
        if (! $this->data->hasDates()) {
            return;
        }

        // A redirect-style search bar (redirectTo && !live) shows its results on
        // another page: seed only, never redirect() from mount. The session
        // carries the search over when the visitor submits. The one-shot flag
        // stays unclaimed so a later live bar can still dispatch for this page's
        // receivers.
        if ($this->redirectTo && ! $this->live) {
            return;
        }

        // Guards against invalid session-carried state (stale dates or quantity):
        // a seeded page load never dispatches a search the receivers would reject.
        if ($this->searchDataIsValid()) {
            request()->attributes->set('resrv-url-seed-dispatched', true);

            $this->dispatchSeededSearch();
        }
    }

    /**
     * The seeded dispatch carries the URL values verbatim instead of this bar's
     * context-reconciled copy: dispatch() is global, so on a page mixing rate
     * contexts the one dispatching bar speaks for the URL, and every receiving
     * component already reconciles the rate for its own context on receipt.
     * A snapshot array (not $this->data itself) keeps the payload immune to the
     * reconciliation mount() runs right after seeding.
     */
    protected function dispatchSeededSearch(): void
    {
        $payload = $this->data->all();

        if (! $payload['rate'] && $this->anyRate) {
            $payload['rate'] = 'any';
        }

        $this->dispatch('availability-search-updated', $payload);
    }

    protected function applyRateFromQuery(array $query): bool
    {
        $rate = $query['rate'] ?? null;

        if (! is_string($rate) || ($rate !== 'any' && ! ctype_digit($rate))) {
            return false;
        }

        // reconcileRateForContext() / the receiving results components drop
        // anything invalid for their context.
        $this->data->rate = $rate;

        return true;
    }

    protected function applyDatesFromQuery(array $query): bool
    {
        $dates = $this->datesFromQuery($query);

        if ($dates === null) {
            return false;
        }

        $previous = $this->data->dates;
        $this->data->dates = $dates;

        if (! $this->candidateDatesAreValid()) {
            // Invalid URL dates are ignored silently.
            $this->data->dates = $previous;

            return false;
        }

        return true;
    }

    /**
     * Judges candidate URL dates on the date rules alone: unrelated stale session
     * state (e.g. a quantity above a since-lowered maximum) must not reject a
     * valid deep link.
     */
    protected function candidateDatesAreValid(): bool
    {
        return $this->searchDataIsValid(
            Arr::only($this->data->rules(), ['dates', 'dates.date_start', 'dates.date_end'])
        );
    }

    protected function datesFromQuery(array $query): ?array
    {
        // The explicit pair wins over the ?date= shorthand; a partial or
        // malformed pair invalidates the whole date seed.
        if (isset($query['date_start']) || isset($query['date_end'])) {
            $start = $this->parseSeedDate($query['date_start'] ?? null);
            $end = $this->parseSeedDate($query['date_end'] ?? null);

            if (! $start || ! $end) {
                return null;
            }

            return ['date_start' => $start->toDateString(), 'date_end' => $end->toDateString()];
        }

        if ($start = $this->parseSeedDate($query['date'] ?? null)) {
            // Same derivation as availabilityDateSelected().
            $minimumPeriod = max(1, config('resrv-config.minimum_reservation_period_in_days', 1));

            return [
                'date_start' => $start->toDateString(),
                'date_end' => $start->copy()->addDays($minimumPeriod)->toDateString(),
            ];
        }

        return null;
    }

    protected function parseSeedDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        // createFromFormat() returns null instead of throwing when the host app
        // disables Carbon strict mode.
        if ($date === null) {
            return null;
        }

        $date = $date->startOfDay();

        // Reject roll-over dates (2026-02-30 parses as March 2nd).
        return $date->toDateString() === $value ? $date : null;
    }

    /**
     * Runs the form's rules — all of them, or the given subset — without letting
     * a failure surface on page load. Broad catch on purpose: the Resrv rules
     * Carbon::create() their values (no `bail`), so stale session data can throw
     * non-validation exceptions — a seeded page load must never 500.
     */
    protected function searchDataIsValid(?array $rules = null): bool
    {
        try {
            $this->data->validate($rules);

            return true;
        } catch (\Throwable) {
            $this->resetValidation();

            return false;
        }
    }
}

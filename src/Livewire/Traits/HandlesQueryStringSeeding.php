<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

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
        // A page can hold several search bars; the first one seeds the shared
        // session (its dehydrate runs before later components mount), so only
        // seed/dispatch once per request.
        if (request()->attributes->get('resrv-url-seeded')) {
            return;
        }

        $query = request()->query();

        $rateSeeded = $this->applyRateFromQuery($query);
        $datesSeeded = $this->applyDatesFromQuery($query);

        if (! $rateSeeded && ! $datesSeeded) {
            return;
        }

        request()->attributes->set('resrv-url-seeded', true);

        // Nothing to search without dates (URL-seeded or carried in the session);
        // the seeded rate still preselects the control and persists via #[Session].
        if (! $this->data->hasDates()) {
            return;
        }

        // A redirect-style search bar (redirectTo && !live) shows its results on
        // another page: seed only, never redirect() from mount. The session
        // carries the search over when the visitor submits.
        if ($this->redirectTo && ! $this->live) {
            return;
        }

        // Guards the rate-only seed against invalid session-carried dates.
        if ($this->searchDataIsValid()) {
            $this->dispatchSearch();
        }
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

        if (! $this->searchDataIsValid()) {
            // Invalid URL dates are ignored silently.
            $this->data->dates = $previous;

            return false;
        }

        return true;
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
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }

        // Reject roll-over dates (2026-02-30 parses as March 2nd).
        return $date->toDateString() === $value ? $date : null;
    }

    /**
     * Runs the form's own rules (ResrvAfterToday, ResrvMinimumDate, duration,
     * before/after) without letting a failure surface on page load. Broad catch
     * on purpose: the Resrv rules Carbon::create() their values (no `bail`), so
     * stale session data can throw non-validation exceptions — a seeded page
     * load must never 500.
     */
    protected function searchDataIsValid(): bool
    {
        try {
            $this->data->validate();

            return true;
        } catch (\Throwable) {
            $this->resetValidation();

            return false;
        }
    }
}

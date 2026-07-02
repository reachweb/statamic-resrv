<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilitySearchQueryParamsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->setTime(12, 0, 0);
        $this->travelTo(today()->setHour(12));
    }

    protected function day(int $offset): string
    {
        return $this->date->copy()->addDays($offset)->toDateString();
    }

    public function test_seeds_explicit_date_range_from_url()
    {
        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ])
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->day(5),
                    'date_end' => $this->day(7),
                ],
                'quantity' => 1,
                'rate' => null,
                'customer' => [],
            ])
            ->assertSessionHas('resrv-search')
            ->assertHasNoErrors()
            ->assertStatus(200);
    }

    public function test_seeds_single_date_param_and_derives_date_end()
    {
        Livewire::withQueryParams(['date' => $this->day(5)])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(6),
            ])
            ->assertDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_single_date_derivation_respects_minimum_reservation_period()
    {
        Config::set('resrv-config.minimum_reservation_period_in_days', 3);

        Livewire::withQueryParams(['date' => $this->day(5)])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(8),
            ])
            ->assertDispatched('availability-search-updated');
    }

    public function test_explicit_range_wins_over_single_date_param()
    {
        Livewire::withQueryParams([
            'date' => $this->day(10),
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ]);
    }

    public function test_url_dates_override_session_persisted_search()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->day(3),
                'date_end' => $this->day(4),
            ]);

        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ]);

        // The overridden search persists for subsequent param-less page loads.
        Livewire::withQueryParams([])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ]);
    }

    public function test_malformed_date_params_are_ignored()
    {
        foreach (['not-a-date', '2026-5-5', '05-05-2026', ''] as $malformed) {
            Livewire::withQueryParams(['date' => $malformed])
                ->test(AvailabilitySearch::class)
                ->assertSet('data.dates', [])
                ->assertNotDispatched('availability-search-updated')
                ->assertHasNoErrors()
                ->assertStatus(200);
        }
    }

    public function test_impossible_date_is_ignored()
    {
        // Well-formed but rolls over (Feb 30th parses as March 2nd).
        Livewire::withQueryParams(['date' => $this->date->copy()->addYear()->year.'-02-30'])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_past_date_is_ignored()
    {
        Livewire::withQueryParams(['date' => $this->date->copy()->subDays(5)->toDateString()])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_date_violating_minimum_days_before_is_ignored()
    {
        Config::set('resrv-config.minimum_days_before', 7);

        Livewire::withQueryParams(['date' => $this->day(2)])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_date_end_not_after_date_start_is_ignored()
    {
        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(3),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();

        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(5),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_date_range_exceeding_maximum_period_is_ignored()
    {
        Config::set('resrv-config.maximum_reservation_period_in_days', 2);

        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(9),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_partial_date_pair_is_ignored()
    {
        Livewire::withQueryParams(['date_start' => $this->day(5)])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();

        // A (partial) explicit pair invalidates the whole date seed, even
        // when a valid ?date= shorthand is also present.
        Livewire::withQueryParams([
            'date' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_invalid_url_dates_keep_previous_session_search()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->day(3),
                'date_end' => $this->day(4),
            ]);

        Livewire::withQueryParams(['date' => 'not-a-date'])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.dates', [
                'date_start' => $this->day(3),
                'date_end' => $this->day(4),
            ])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_seeds_rate_with_dates_on_context_less_rate_bar()
    {
        // Context-less rate search bar (entry === null, rates === true): reconcileRate
        // is skipped, so the seeded rate is handed to the results components as-is.
        Livewire::withQueryParams([
            'rate' => '7',
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class, ['rates' => true])
            ->assertSet('data.rate', '7')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->day(5),
                    'date_end' => $this->day(7),
                ],
                'quantity' => 1,
                'rate' => '7',
                'customer' => [],
            ]);
    }

    public function test_invalid_rate_is_dropped_on_entry_scoped_bar()
    {
        // 'pages' has exactly one rate, so reconcileRate drops the bogus URL rate
        // and auto-selects the single valid one.
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $rateId = Rate::forEntry($entry->id())->first()->id;

        Livewire::withQueryParams([
            'rate' => '99999',
            'date_start' => $this->day(1),
            'date_end' => $this->day(2),
        ])
            ->test(AvailabilitySearch::class, ['rates' => true, 'entry' => $entry->id()])
            ->assertSet('data.rate', (string) $rateId)
            ->assertDispatched('availability-search-updated');
    }

    public function test_valid_rate_sticks_on_entry_scoped_bar()
    {
        // Two rates on the entry so auto-select cannot mask the seeded value.
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $secondRate = $this->createRateForEntry($entry, ['slug' => 'second', 'title' => 'Second']);

        Livewire::withQueryParams([
            'rate' => (string) $secondRate->id,
            'date_start' => $this->day(1),
            'date_end' => $this->day(2),
        ])
            ->test(AvailabilitySearch::class, ['rates' => true, 'entry' => $entry->id()])
            ->assertSet('data.rate', (string) $secondRate->id)
            ->assertDispatched('availability-search-updated');
    }

    public function test_rate_is_cleared_when_rates_disabled()
    {
        Livewire::withQueryParams([
            'rate' => '5',
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class)
            ->assertSet('data.rate', null)
            ->assertDispatched('availability-search-updated');
    }

    public function test_rate_only_seed_reuses_session_dates()
    {
        Livewire::test(AvailabilitySearch::class, ['rates' => true])
            ->set('data.dates', [
                'date_start' => $this->day(3),
                'date_end' => $this->day(4),
            ]);

        Livewire::withQueryParams(['rate' => '7'])
            ->test(AvailabilitySearch::class, ['rates' => true])
            ->assertSet('data.rate', '7')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->day(3),
                    'date_end' => $this->day(4),
                ],
                'quantity' => 1,
                'rate' => '7',
                'customer' => [],
            ]);
    }

    public function test_rate_only_seed_without_dates_does_not_dispatch_or_error()
    {
        Livewire::withQueryParams(['rate' => '7'])
            ->test(AvailabilitySearch::class, ['rates' => true])
            ->assertSet('data.rate', '7')
            ->assertSet('data.dates', [])
            ->assertNotDispatched('availability-search-updated')
            ->assertHasNoErrors();
    }

    public function test_rate_any_is_accepted()
    {
        Livewire::withQueryParams([
            'rate' => 'any',
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class, ['rates' => true, 'anyRate' => true])
            ->assertSet('data.rate', 'any')
            ->assertDispatched('availability-search-updated');
    }

    public function test_non_numeric_rate_is_ignored()
    {
        foreach (['abc', '1.5', '-3', ''] as $invalid) {
            Livewire::withQueryParams([
                'rate' => $invalid,
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ])
                ->test(AvailabilitySearch::class, ['rates' => true])
                ->assertSet('data.rate', null)
                ->assertHasNoErrors();
        }
    }

    public function test_later_search_bar_on_same_page_still_seeds_url_rate()
    {
        // Two bars on one page share the 'resrv-search' session: the rates-disabled
        // bar renders first, consumes the one-shot dispatch and clears the rate for
        // its own context before the second bar mounts. The rates-enabled bar must
        // still seed ?rate= from the URL instead of inheriting the reconciled copy.
        request()->merge([
            'rate' => '7',
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ]);

        Blade::render('@livewire("availability-search")@livewire("availability-search", ["rates" => true])');

        Livewire::test(AvailabilitySearch::class, ['rates' => true])
            ->assertSet('data.rate', '7');
    }

    public function test_seeding_never_redirects_redirect_style_search_bar()
    {
        // A redirect-style search bar (redirectTo && !live) shows results on another
        // page: the seed persists to the session but must neither dispatch (nothing
        // is listening) nor bounce the visitor away on page load.
        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class, ['live' => false, 'redirectTo' => '/results'])
            ->assertSet('data.dates', [
                'date_start' => $this->day(5),
                'date_end' => $this->day(7),
            ])
            ->assertNotDispatched('availability-search-updated')
            ->assertNoRedirect()
            ->assertSessionHas('resrv-search');
    }

    public function test_seeding_dispatches_when_live_is_false_without_redirect()
    {
        Livewire::withQueryParams([
            'date_start' => $this->day(5),
            'date_end' => $this->day(7),
        ])
            ->test(AvailabilitySearch::class, ['live' => false])
            ->assertDispatched('availability-search-updated');
    }
}

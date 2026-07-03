<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;
use Reach\StatamicResrv\Models\Rate;

/**
 * Cross-collection rate reconciliation across REAL browser navigations — the one thing
 * the headless Livewire::test suite can only approximate. `#[Session('resrv-search')]`
 * is shared across every availability component AND across page loads, so a rate chosen
 * on one collection is carried to the next; `AvailabilityData::reconcileRate()` heals it:
 * it drops a numeric rate not valid in the current context and auto-selects when exactly
 * one valid rate exists.
 *
 * Fixtures (SeedsBookableContent): collection A = `pages` (A1 = the `multi` entry, two
 * rates incl. `children`); collection B = `rooms` with B1 = `room-flex` (two rates) and
 * B2 = `room-solo` (one rate `rooms-solo`). Rate ids are mutually foreign across the two
 * collections.
 */
class CrossCollectionRateReconcileTest extends BrowserTestCase
{
    public function test_foreign_rate_is_dropped_then_a_single_valid_rate_auto_selects(): void
    {
        ['foreignRate' => $foreignRate, 'roomsFlex' => $roomsFlex, 'roomsStandard' => $roomsStandard, 'roomsSolo' => $roomsSolo] = $this->rateFixtures();
        [$start, $end] = $this->searchDates();

        $this->browse(function (Browser $browser) use ($foreignRate, $roomsFlex, $roomsStandard, $roomsSolo, $start, $end) {
            // A1 (collection A): choose the foreign rate + dates, persisting to resrv-search.
            $this->carryForeignRateAndDates($browser, $foreignRate, $start, $end);

            // --- Step 1: navigate to B1 (collection B, two rates) — the foreign rate is dropped ---
            $browser->visit('/__t/rate-entry/room-flex')
                ->waitFor('#availability-search-rate')
                ->waitFor('[wire\\:click^="checkoutRate"]'); // B's results render (foreign rate did not empty them)

            $this->assertNotSame((string) $foreignRate, $browser->value('#availability-search-rate'), 'B1 must not carry the foreign rate as its selected value.');

            $options = $this->rateSelectOptions($browser);
            $this->assertNotContains((string) $foreignRate, $options, 'B1 must not offer the foreign rate.');
            $this->assertContains((string) $roomsFlex, $options, 'B1 must offer its own rates.');
            $this->assertContains((string) $roomsStandard, $options);

            // --- Step 2: navigate to B2 (collection B, exactly one rate) — it auto-selects ---
            $browser->visit('/__t/rate-entry/room-solo')
                ->waitFor('#availability-search-rate')
                ->waitUsing(6, 100, fn () => $browser->value('#availability-search-rate') === (string) $roomsSolo);

            $this->assertSame((string) $roomsSolo, $browser->value('#availability-search-rate'), 'B2 must auto-select its single valid rate.');
        });
    }

    public function test_collection_listing_scopes_to_its_own_rates(): void
    {
        ['foreignRate' => $foreignRate, 'roomsFlex' => $roomsFlex, 'roomsSolo' => $roomsSolo] = $this->rateFixtures();
        [$start, $end] = $this->searchDates();

        $this->browse(function (Browser $browser) use ($foreignRate, $roomsFlex, $roomsSolo, $start, $end) {
            $this->carryForeignRateAndDates($browser, $foreignRate, $start, $end);

            // Mount the rooms collection listing while a collection-A rate is still in session.
            $browser->visit('/__t/rate-collection')
                ->waitFor('[dusk=rate-collection-route]')
                ->waitForText('Flex Room');

            // The foreign rate did not survive as a WHERE filter that empties the list.
            $browser->assertSee('Flex Room')->assertSee('Solo Room');

            // The Book Now buttons offer only B's rates.
            $offeredRateIds = collect($browser->script(
                'return Array.from(document.querySelectorAll("[dusk=rate-collection-route] button")).map(b => b.getAttribute("wire:click") || "");'
            )[0])
                ->filter(fn ($click) => str_starts_with($click, 'select('))
                ->map(fn ($click) => preg_match('/,\s*(\d+)\)/', $click, $m) ? $m[1] : null)
                ->filter()->unique()->values()->all();

            $this->assertNotContains((string) $foreignRate, $offeredRateIds, 'The listing must not offer the foreign rate.');
            $this->assertContains((string) $roomsFlex, $offeredRateIds, 'The listing must offer its own rates.');
            $this->assertContains((string) $roomsSolo, $offeredRateIds);
        });
    }

    public function test_context_less_rate_bar_keeps_a_manually_set_rate(): void
    {
        ['roomsFlex' => $roomsFlex] = $this->rateFixtures();
        [$start, $end] = $this->searchDates();

        $this->browse(function (Browser $browser) use ($roomsFlex, $start, $end) {
            $browser->visit('/__t/rate-bar')->waitFor('#availability-search-rate');

            // Manually set a rate on the context-less bar (no entry), then trigger a real
            // Livewire round-trip by setting dates.
            $browser->script(<<<JS
                const c = window.Livewire.all().find(x => x.el.closest('[dusk=rate-bar-route]'));
                window.Livewire.find(c.id).set('data.rate', $roomsFlex);
            JS);
            $browser->pause(500);
            $browser->script(<<<JS
                const c = window.Livewire.all().find(x => x.el.closest('[dusk=rate-bar-route]'));
                window.Livewire.find(c.id).set('data.dates', { date_start: '$start', date_end: '$end' });
            JS);
            $browser->pause(800);

            // reconcileRateForContext() leaves a context-less bar's rate alone (no entry to
            // judge it against), so the manually set rate survives the round-trip — the fix
            // did not reintroduce a wipe.
            $rate = $browser->script(<<<'JS'
                const c = window.Livewire.all().find(x => x.el.closest('[dusk=rate-bar-route]'));
                return String(window.Livewire.find(c.id).get('data.rate'));
            JS)[0];

            $this->assertSame((string) $roomsFlex, $rate, 'The context-less bar must keep a manually set rate.');
        });
    }

    /**
     * A1: select the foreign rate on the two-rate `multi` entry and set dates, so the
     * shared resrv-search session carries both into the next navigation. Waiting for the
     * Book Now action confirms the live search round-trip persisted.
     */
    private function carryForeignRateAndDates(Browser $browser, int $foreignRate, string $start, string $end): void
    {
        $browser->visit('/__t/rate-entry/multi')
            ->waitFor('#availability-search-rate')
            ->select('#availability-search-rate', (string) $foreignRate)
            ->waitUsing(5, 100, fn () => $browser->value('#availability-search-rate') === (string) $foreignRate);

        $browser->script(<<<JS
            const c = window.Livewire.all().find(x => x.el.closest('[dusk=rate-entry-route]'));
            window.Livewire.find(c.id).set('data.dates', { date_start: '$start', date_end: '$end' });
        JS);

        $browser->waitFor('[wire\\:click="checkout()"]');
    }

    /**
     * @return list<string> the option values of the search rate <select>.
     */
    private function rateSelectOptions(Browser $browser): array
    {
        return $browser->script(
            'return Array.from(document.querySelectorAll("#availability-search-rate option")).map(o => o.value);'
        )[0];
    }

    /**
     * @return array{foreignRate: int, roomsFlex: int, roomsStandard: int, roomsSolo: int}
     */
    private function rateFixtures(): array
    {
        return [
            'foreignRate' => (int) Rate::where('collection', 'pages')->where('slug', 'children')->value('id'),
            'roomsFlex' => (int) Rate::where('collection', 'rooms')->where('slug', 'rooms-flex')->value('id'),
            'roomsStandard' => (int) Rate::where('collection', 'rooms')->where('slug', 'rooms-standard')->value('id'),
            'roomsSolo' => (int) Rate::where('collection', 'rooms')->where('slug', 'rooms-solo')->value('id'),
        ];
    }

    /**
     * @return array{0: string, 1: string} start/end dates inside the seeded availability window.
     */
    private function searchDates(): array
    {
        return [today()->addDays(5)->toDateString(), today()->addDays(7)->toDateString()];
    }
}

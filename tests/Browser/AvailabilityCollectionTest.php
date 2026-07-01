<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Facades\Entry;

/**
 * AvailabilityCollection — the live collection listing (the no-extra-package component). Drives
 * the seeded `rooms` collection (SeedsBookableContent) through a real browser: the batched
 * availability list render, the #[Locked] showUnavailable mount option compared across two
 * mounted instances (not a UI toggle), entry-level pagination page nav, and select() → the
 * entry's detail-page redirect. State transitions and validation stay headless
 * (tests/Livewire/AvailabilityCollectionTest); this proves only the rendered/reactive surface.
 *
 * Fixtures: collection `rooms` with `room-flex` (two rates) and `room-solo` (one rate), both
 * available across the seeded +1…+20 window; each entry has a `room` template so its detail
 * page renders for the redirect assertion.
 */
class AvailabilityCollectionTest extends BrowserTestCase
{
    public function test_lists_the_available_entries_in_a_collection(): void
    {
        [$flexId, $soloId] = $this->roomEntryIds();

        $this->browse(function (Browser $browser) use ($flexId, $soloId) {
            $this->carrySearchDates($browser);

            $browser->visit('/__t/collection')
                ->waitFor('[dusk=collection-route] [role=listitem]');

            // Both seeded rooms are available for the search window, so both render a bookable row.
            $this->assertCount(2, $browser->elements('[dusk=collection-route] [role=listitem]'));

            $bookable = $this->selectableEntryIds($browser, '[dusk=collection-route]');
            $this->assertContains($flexId, $bookable);
            $this->assertContains($soloId, $bookable);
        });
    }

    public function test_show_unavailable_renders_sold_out_rows_only_when_enabled(): void
    {
        [$flexId, $soloId] = $this->roomEntryIds();

        // Sell out the solo room for every date; the flex room stays available. The served
        // process reads this from the shared file DB (availability is not Stache-cached).
        Availability::where('statamic_id', $soloId)->update(['available' => 0]);

        $this->browse(function (Browser $browser) use ($flexId) {
            $this->carrySearchDates($browser);

            $browser->visit('/__t/collection-compare')
                ->waitFor('[dusk=col-show] [role=listitem]');

            // showUnavailable = true: both rows render, but only the flex room is bookable —
            // the sold-out solo room shows with no Book Now action.
            $this->assertCount(2, $browser->elements('[dusk=col-show] [role=listitem]'));
            $this->assertSame([$flexId], $this->selectableEntryIds($browser, '[dusk=col-show]'));

            // showUnavailable = false (default): the sold-out solo room is filtered out entirely.
            $this->assertCount(1, $browser->elements('[dusk=col-hide] [role=listitem]'));
            $this->assertSame([$flexId], $this->selectableEntryIds($browser, '[dusk=col-hide]'));
        });
    }

    public function test_paginate_navigates_between_pages(): void
    {
        [$flexId, $soloId] = $this->roomEntryIds();
        $roomIds = [$flexId, $soloId];

        $this->browse(function (Browser $browser) use ($roomIds) {
            $this->carrySearchDates($browser);

            $browser->visit('/__t/collection-paginate')
                ->waitFor('[dusk=collection-paginate-route] [role=listitem]');

            // paginate = 1 → exactly one entry on the first page.
            $this->assertCount(1, $browser->elements('[dusk=collection-paginate-route] [role=listitem]'));
            $firstId = $this->paginatedEntryId($browser);
            $this->assertContains($firstId, $roomIds);

            // Drive the real pagination round-trip (the rendered [dusk=nextPage] link calls the
            // same nextPage() action; poked here to avoid the tailwind view's dual mobile/desktop
            // hooks). Page 2 re-queries and renders the other entry.
            $browser->script(<<<'JS'
                const c = window.Livewire.all().find(x => x.el.closest('[dusk=collection-paginate-route]'));
                window.Livewire.find(c.id).call('nextPage');
            JS);

            $browser->waitUsing(6, 100, fn () => ($id = $this->paginatedEntryId($browser)) !== null && $id !== $firstId);

            $this->assertCount(1, $browser->elements('[dusk=collection-paginate-route] [role=listitem]'));
            $secondId = $this->paginatedEntryId($browser);
            $this->assertContains($secondId, $roomIds);
            $this->assertNotSame($firstId, $secondId, 'Page 2 must render the other entry.');
        });
    }

    public function test_select_redirects_to_the_entry_detail_page(): void
    {
        [$flexId] = $this->roomEntryIds();
        $flexButton = '[dusk=collection-route] [wire\\:click^="select(\''.$flexId.'\'"]';

        $this->browse(function (Browser $browser) use ($flexButton) {
            $this->carrySearchDates($browser);

            $browser->visit('/__t/collection')
                ->waitFor($flexButton)
                ->click($flexButton)
                ->waitForLocation('/rooms/room-flex')
                ->assertPresent('[dusk=room-detail]');
        });
    }

    /**
     * Carry a valid search into the shared #[Session('resrv-search')] via a real page so a
     * subsequent full-page navigation to a collection route reads the dates on mount and renders
     * rows (mirrors the T20 cross-collection pattern). room-flex has two rates, so no single rate
     * auto-selects and the collection resolves all rates for the listing.
     */
    private function carrySearchDates(Browser $browser): void
    {
        [$start, $end] = $this->searchDates();

        $browser->visit('/__t/rate-entry/room-flex')
            ->waitFor('#availability-search-rate');

        $browser->script(<<<JS
            const c = window.Livewire.all().find(x => x.el.closest('[dusk=rate-entry-route]'));
            window.Livewire.find(c.id).set('data.dates', { date_start: '$start', date_end: '$end' });
        JS);

        // The advanced rate selector rendering confirms the search round-trip resolved and the
        // dates persisted to the session (room-flex has two rates → no single Book Now button).
        $browser->waitFor('[wire\\:click^="checkoutRate"]');
    }

    /**
     * The entry ids that carry a Book Now (select) action within a container — the rendered,
     * DOM-stable proof of which rows are bookable, independent of translated text (mirrors the
     * T20 wire:click-scraping approach).
     *
     * @return list<string>
     */
    private function selectableEntryIds(Browser $browser, string $container): array
    {
        return collect($browser->script(
            "return Array.from(document.querySelectorAll('$container [role=listitem] button')).map(b => b.getAttribute('wire:click') || '');"
        )[0])
            ->filter(fn ($click) => str_starts_with($click, 'select('))
            ->map(fn ($click) => preg_match("/select\\('([^']+)'/", $click, $m) ? $m[1] : null)
            ->filter()->values()->all();
    }

    private function paginatedEntryId(Browser $browser): ?string
    {
        return $this->selectableEntryIds($browser, '[dusk=collection-paginate-route]')[0] ?? null;
    }

    /**
     * @return array{0: string, 1: string} the Statamic ids of the flex and solo room entries.
     */
    private function roomEntryIds(): array
    {
        return [
            (string) Entry::query()->where('collection', 'rooms')->where('slug', 'room-flex')->first()?->id(),
            (string) Entry::query()->where('collection', 'rooms')->where('slug', 'room-solo')->first()?->id(),
        ];
    }

    /**
     * @return array{0: string, 1: string} start/end inside the seeded +1…+20 availability window.
     */
    private function searchDates(): array
    {
        return [today()->addDays(5)->toDateString(), today()->addDays(7)->toDateString()];
    }
}

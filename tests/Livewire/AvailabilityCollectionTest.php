<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityCollection;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\EntryCollection;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class AvailabilityCollectionTest extends TestCase
{
    use CreatesEntries;

    public $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->travelTo(today()->setHour(12));
    }

    protected function search($component, $rate = null, $quantity = 1, $start = null, $end = null)
    {
        return $component->dispatch('availability-search-updated', [
            'dates' => [
                'date_start' => ($start ?? $this->date)->toISOString(),
                'date_end' => ($end ?? $this->date->copy()->add(2, 'day'))->toISOString(),
            ],
            'quantity' => $quantity,
            'rate' => $rate,
        ]);
    }

    protected function pagesEntry(int $price, int $available = 1): \Statamic\Entries\Entry
    {
        return $this->makeStatamicItemWithAvailability(collection: 'pages', available: $available, price: $price);
    }

    protected function createCheckoutEntry(): \Statamic\Entries\Entry
    {
        $this->ensureCollectionExists('pages');

        $entry = Entry::make()->collection('pages')->slug('checkout')->data(['title' => 'Checkout']);
        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        return $entry;
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
            ->assertViewIs('statamic-resrv::livewire.availability-collection')
            ->assertStatus(200);
    }

    public function test_requires_a_collection_or_entries()
    {
        // The InvalidArgumentException thrown in mount() surfaces wrapped in a
        // ViewException during Livewire's initial render, so assert on the message.
        try {
            Livewire::test(AvailabilityCollection::class);
            $this->fail('Expected the component to require a collection or entries.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('requires a "collection" handle or an "entries" array', $exception->getMessage());
        }
    }

    public function test_lists_available_entries_for_the_searched_dates()
    {
        $this->pagesEntry(price: 50);
        $this->pagesEntry(price: 30);
        $this->pagesEntry(price: 45);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
        );

        // One booking button per available entry, each showing its (2-night) total.
        $this->assertEquals(3, substr_count($component->html(), 'Book now'));
        $component->assertSee('100.00')->assertSee('60.00')->assertSee('90.00');
    }

    public function test_intersects_collection_and_explicit_entries()
    {
        $a = $this->pagesEntry(price: 50);
        $b = $this->pagesEntry(price: 30);
        $c = $this->pagesEntry(price: 45);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'entries' => [$a->id(), $b->id()],
            ])
        );

        $this->assertEquals(2, substr_count($component->html(), 'Book now'));
        $component->assertSee('resrv-collection-'.$a->id())
            ->assertSee('resrv-collection-'.$b->id())
            ->assertDontSee('resrv-collection-'.$c->id());
    }

    public function test_hides_unavailable_entries_by_default()
    {
        $available = $this->pagesEntry(price: 50, available: 1);
        $soldOut = $this->pagesEntry(price: 30, available: 0);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
        );

        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
        $component->assertSee('resrv-collection-'.$available->id())
            ->assertDontSee('resrv-collection-'.$soldOut->id());
    }

    public function test_shows_unavailable_entries_when_enabled()
    {
        $available = $this->pagesEntry(price: 50, available: 1);
        $soldOut = $this->pagesEntry(price: 30, available: 0);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'showUnavailable' => true,
            ])
        );

        // Both rows render, but only the available one is bookable.
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
        $component->assertSee('resrv-collection-'.$available->id())
            ->assertSee('resrv-collection-'.$soldOut->id())
            ->assertSee('No availability');
    }

    public function test_shows_a_single_from_price_by_default()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B']);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true])
        );

        // Only the cheapest ("from") price is shown.
        $component->assertSee('60.00')->assertDontSee('100.00');
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
    }

    public function test_lists_each_rate_when_show_rates_is_enabled()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B']);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'rates' => true,
                'showRates' => true,
            ])
        );

        // Both rate prices are listed, each with its own booking button.
        $component->assertSee('60.00')->assertSee('100.00');
        $this->assertEquals(2, substr_count($component->html(), 'Book now'));
    }

    public function test_sorts_by_price_when_requested()
    {
        $this->pagesEntry(price: 50); // 100.00
        $this->pagesEntry(price: 30); // 60.00
        $this->pagesEntry(price: 45); // 90.00

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'sort' => 'price'])
        );

        $component->assertSeeInOrder(['60.00', '90.00', '100.00']);
    }

    public function test_paginates_the_results()
    {
        $this->pagesEntry(price: 50);
        $this->pagesEntry(price: 30);
        $this->pagesEntry(price: 45);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'paginate' => 2])
        );

        // Only the first page (2 of 3) renders.
        $this->assertEquals(2, substr_count($component->html(), 'Book now'));
        $this->assertInstanceOf(LengthAwarePaginator::class, $component->instance()->resolvedEntries());
    }

    public function test_returns_no_entries_without_pagination()
    {
        $this->pagesEntry(price: 50);

        $component = Livewire::test(AvailabilityCollection::class, ['collection' => 'pages']);

        $this->assertInstanceOf(EntryCollection::class, $component->instance()->resolvedEntries());
    }

    public function test_select_redirects_to_the_entry_detail_page_when_one_exists()
    {
        $entry = $this->pagesEntry(price: 50);
        $rateId = Rate::forEntry($entry->id())->first()->id;

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true])
        );

        $component->call('select', $entry->id(), $rateId)
            ->assertRedirect($entry->url())
            ->assertSet('data.rate', (string) $rateId);

        // No reservation is created — the detail page handles booking.
        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_select_books_directly_and_redirects_to_checkout_when_no_detail_page()
    {
        // A route-less collection (no ->routes()) => entries have no URL, but it
        // still needs the resrv_availability blueprint so entries are mirrored into
        // resrv_entries (which the per-entry booking path reads).
        $collection = Collection::make('tickets')->save();
        $this->makeBlueprint($collection);

        $entry = $this->makeStatamicItemWithAvailability(collection: 'tickets', price: 50);
        $rateId = Rate::where('collection', 'tickets')->where('slug', 'default')->first()->id;

        $checkout = $this->createCheckoutEntry();

        $this->assertNull($entry->url());

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'tickets', 'rates' => true])
        );

        $component->call('select', $entry->id(), $rateId)
            ->assertRedirect($checkout->url());

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entry->id(),
            'rate_id' => $rateId,
            'status' => 'pending',
        ]);
    }

    public function test_select_rejects_an_entry_outside_the_configured_scope()
    {
        $inScope = $this->pagesEntry(price: 50);
        $outOfScope = $this->pagesEntry(price: 30);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['entries' => [$inScope->id()]])
        );

        $component->call('select', $outOfScope->id())
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_finds_availability_for_localized_entries_on_a_non_default_site()
    {
        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
        ]);
        Site::setCurrent('en');

        $collection = Collection::make('rooms')->routes('/{slug}')->sites(['en', 'el'])->save();
        $this->makeBlueprint($collection);

        $origin = Entry::make()->collection('rooms')->locale('en')->slug('room-en')
            ->data(['title' => 'Room', 'resrv_availability' => Str::random(6)]);
        $origin->save();
        $origin = Entry::query()->where('slug', 'room-en')->first();

        $localized = $origin->makeLocalization('el');
        $localized->slug('room-el');
        $localized->save();

        $rate = Rate::factory()->create(['collection' => 'rooms', 'slug' => 'default', 'title' => 'Default']);
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $origin->id(),
                'available' => 1,
                'price' => 50,
                'rate_id' => $rate->id,
            ]);

        Site::setCurrent('el');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'rooms'])
        );

        $component->assertSee('resrv-collection-'.$localized->id())
            ->assertSee('100.00');
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
    }

    public function test_finds_availability_for_explicit_origin_entry_ids_on_a_non_default_site()
    {
        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
        ]);
        Site::setCurrent('en');

        $collection = Collection::make('rooms')->routes('/{slug}')->sites(['en', 'el'])->save();
        $this->makeBlueprint($collection);

        $origin = Entry::make()->collection('rooms')->locale('en')->slug('room-en')
            ->data(['title' => 'Room', 'resrv_availability' => Str::random(6)]);
        $origin->save();
        $origin = Entry::query()->where('slug', 'room-en')->first();

        $localized = $origin->makeLocalization('el');
        $localized->slug('room-el');
        $localized->save();

        $rate = Rate::factory()->create(['collection' => 'rooms', 'slug' => 'default', 'title' => 'Default']);
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $origin->id(),
                'available' => 1,
                'price' => 50,
                'rate_id' => $rate->id,
            ]);

        Site::setCurrent('el');

        // The component is configured with the ORIGIN (default-site) entry id, but
        // we're browsing the Greek site whose localization carries a different id.
        // The query must still resolve the localization via its origin reference.
        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['entries' => [$origin->id()]])
        );

        $component->assertSee('resrv-collection-'.$localized->id())
            ->assertSee('100.00');
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
    }

    public function test_select_rejects_an_entry_from_another_site()
    {
        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
        ]);
        Site::setCurrent('en');

        $collection = Collection::make('rooms')->routes('/{slug}')->sites(['en', 'el'])->save();
        $this->makeBlueprint($collection);

        $origin = Entry::make()->collection('rooms')->locale('en')->slug('room-en')
            ->data(['title' => 'Room', 'resrv_availability' => Str::random(6)]);
        $origin->save();
        $origin = Entry::query()->where('slug', 'room-en')->first();

        $localized = $origin->makeLocalization('el');
        $localized->slug('room-el');
        $localized->save();

        $rate = Rate::factory()->create(['collection' => 'rooms', 'slug' => 'default', 'title' => 'Default']);
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $origin->id(),
                'available' => 1,
                'price' => 50,
                'rate_id' => $rate->id,
            ]);

        // Browsing the Greek site: only the Greek localization is listed.
        Site::setCurrent('el');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'rooms'])
        );

        // The English origin entry never appears in the Greek listing, so selecting it
        // (a forged/stale call) must be rejected rather than redirecting cross-site.
        $component->call('select', $origin->id())
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_select_enforces_cutoff_rules_on_the_direct_booking_path()
    {
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Route-less collection => entries have no detail URL, so select() books directly.
        $collection = Collection::make('tickets')->save();
        $this->makeBlueprint($collection);

        $entry = $this->makeStatamicItemWithAvailability(collection: 'tickets', price: 50);

        // Cutoff rules live on the resrv_entries mirror's options column, not on the
        // Statamic entry. A 48h window before the searched (tomorrow) date is already
        // in the past relative to "now" (today noon, per setUp), so booking is blocked.
        $resrvEntry = ResrvEntry::whereItemId($entry->id());
        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '09:00',
                'default_cutoff_hours' => 48,
            ],
        ];
        $resrvEntry->save();

        $rateId = Rate::where('collection', 'tickets')->where('slug', 'default')->first()->id;

        $this->createCheckoutEntry();

        $this->assertNull($entry->url());

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'tickets', 'rates' => true])
        );

        // The searched (tomorrow) dates fall inside the 24h cutoff window, so the
        // direct booking is blocked: an error is shown and no reservation is created.
        $component->call('select', $entry->id(), $rateId)
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_keeps_pagination_visible_when_the_current_page_is_empty()
    {
        // Page 1 holds two sold-out entries; an available entry sits on page 2.
        // Entries are paginated before availability is filtered, so page 1 renders
        // empty — but the pagination links must still appear so page 2 is reachable.
        $this->pagesEntry(price: 50, available: 0);
        $this->pagesEntry(price: 30, available: 0);
        $this->pagesEntry(price: 45, available: 1);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'paginate' => 2])
        );

        // The current (first) page is empty...
        $component->assertSee('No availability');
        $this->assertEquals(0, substr_count($component->html(), 'Book now'));

        // ...but the pagination control is still rendered so later pages are reachable.
        $this->assertTrue($component->instance()->resolvedEntries()->hasPages());
        $component->assertSeeHtml('gotoPage(2');
    }

    public function test_excludes_private_scheduled_entries_from_the_listing()
    {
        // Dated collection where future-dated entries are private (scheduled).
        $collection = Collection::make('events')->routes('/{slug}')->dated(true)->futureDateBehavior('private');
        $collection->save();
        $this->makeBlueprint($collection);

        // Public entry: dated in the past (past behavior defaults to public).
        $public = $this->makeStatamicItemWithAvailability(collection: 'events', price: 50);
        $public->date(now()->subDay())->save();

        // Scheduled entry: future date => private even though published === true.
        $scheduled = $this->makeStatamicItemWithAvailability(collection: 'events', price: 30);
        $scheduled->date(now()->addDays(10))->save();

        // Sanity: the scheduled entry really is published-but-private.
        $fresh = Entry::find($scheduled->id());
        $this->assertTrue($fresh->published());
        $this->assertTrue($fresh->private());

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'events'])
        );

        // Only the public entry is listed and bookable.
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
        $component->assertSee('resrv-collection-'.$public->id())
            ->assertDontSee('resrv-collection-'.$scheduled->id());
    }

    public function test_select_rejects_a_private_scheduled_entry()
    {
        $collection = Collection::make('events')->routes('/{slug}')->dated(true)->futureDateBehavior('private');
        $collection->save();
        $this->makeBlueprint($collection);

        $scheduled = $this->makeStatamicItemWithAvailability(collection: 'events', price: 30);
        $scheduled->date(now()->addDays(10))->save();

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'events'])
        );

        // A stale/forged client cannot book the hidden entry directly.
        $component->call('select', $scheduled->id())
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_surfaces_availability_errors_instead_of_an_empty_state()
    {
        // calculate_days_using_time makes the model add a day for a later drop-off
        // time, so a range the form's duration rule accepts (2 calendar days) can
        // still exceed the model's max — an error only the availability model raises.
        Config::set('resrv-config.calculate_days_using_time', true);
        Config::set('resrv-config.maximum_reservation_period_in_days', 2);

        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages']),
            start: now()->add(1, 'day')->setTime(9, 0, 0),
            end: now()->add(3, 'day')->setTime(17, 0, 0),
        );

        // The real reason is surfaced rather than a silent "no availability" state.
        $component->assertHasErrors('availability')
            ->assertSee('exceeds the maximum allowed reservation period');
    }

    public function test_does_not_query_availability_after_an_invalid_search()
    {
        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50);

        // Both date keys are present (so hasDates() passes) but unparseable, so
        // validation fails. Without the searchIsValid guard, rows() still queries
        // and Carbon::parse() throws an uncaught exception while rendering.
        $component = Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => 'not-a-real-date',
                    'date_end' => 'also-not-a-real-date',
                ],
                'quantity' => 1,
                'rate' => null,
            ]);

        // Rendering completed (no uncaught exception) and the error is surfaced.
        $component->assertStatus(200)
            ->assertHasErrors('availability');
    }

    public function test_select_persists_the_resolved_rate_when_booking_directly_without_one()
    {
        // Route-less collection => entries have no URL, so select() books directly.
        $collection = Collection::make('tickets')->save();
        $this->makeBlueprint($collection);

        $entry = $this->makeStatamicItemWithAvailability(collection: 'tickets', price: 50);
        $rateId = Rate::where('collection', 'tickets')->where('slug', 'default')->first()->id;

        $checkout = $this->createCheckoutEntry();

        $this->assertNull($entry->url());

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'tickets', 'rates' => true])
        );

        // select() is called WITHOUT a rate (the argument is nullable, e.g. from a
        // custom view). The availability query resolves a concrete rate, which must be
        // persisted — otherwise rate_id is saved as null and the availability decrement
        // runs unscoped across every rate row for the entry/date range.
        $component->call('select', $entry->id())
            ->assertRedirect($checkout->url());

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entry->id(),
            'rate_id' => $rateId,
            'status' => 'pending',
        ]);
    }
}

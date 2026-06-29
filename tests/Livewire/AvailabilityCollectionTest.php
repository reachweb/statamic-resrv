<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Reach\StatamicResrv\Enums\RateSorting;
use Reach\StatamicResrv\Livewire\AvailabilityCollection;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
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

    protected function pagesEntry(int $price, int $available = 1, ?string $title = null): \Statamic\Entries\Entry
    {
        return $this->makeStatamicItemWithAvailability(collection: 'pages', available: $available, price: $price, title: $title);
    }

    protected function createCheckoutEntry(): \Statamic\Entries\Entry
    {
        $this->ensureCollectionExists('pages');

        $entry = Entry::make()->collection('pages')->slug('checkout')->data(['title' => 'Checkout']);
        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        return $entry;
    }

    /**
     * One entry with two surfacing rates whose Rate.order disagrees with price: a base rate
     * (order 0, 200.00 over the 2-day search) and a cheaper relative shared rate (order 1,
     * 180.00 = 2 * 90). The cheapest rate therefore has the HIGHER order, so order-based and
     * price-based sorting produce different orderings.
     *
     * @return array{0: \Statamic\Entries\Entry, 1: Rate, 2: Rate}
     */
    protected function entryWithCheaperHigherOrderRate(): array
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'title' => 'Base',
            'order' => 0,
        ]);

        $sharedRate = Rate::factory()->shared()->relative()->create([
            'collection' => 'pages',
            'slug' => 'shared-rate',
            'title' => 'Shared',
            'base_rate_id' => $baseRate->id,
            'order' => 1,
            'published' => true,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 5, $baseRate->id, 4);

        return [$entry, $baseRate, $sharedRate];
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
            ->assertViewIs('statamic-resrv::livewire.availability-collection')
            ->assertStatus(200);
    }

    public function test_requires_a_collection_or_entries()
    {
        // mount()'s InvalidArgumentException surfaces wrapped during render, so assert the message.
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

        // One booking button per available entry, each showing its 2-night total.
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
        // rate-a (order 0, 100.00) is pricier than rate-b (order 1, 60.00). The default 'order'
        // rate sorting surfaces the lowest-order rate as the "from" price — not the cheapest —
        // so the collection agrees with the single-entry availability-results component.
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B', 'order' => 1]);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true])
        );

        // Only one ("from") price shows, and it is the lowest-order rate's price.
        $component->assertSee('100.00')->assertDontSee('60.00');
        $this->assertEquals(1, substr_count($component->html(), 'Book now'));
    }

    public function test_from_price_reflects_price_sorting_when_requested()
    {
        // Same setup as the default test, but rate-sorting="price" makes the cheapest rate
        // (rate-b, 60.00) the "from" price instead of the lowest-order one (rate-a, 100.00).
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B', 'order' => 1]);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true, 'rateSorting' => 'price'])
        );

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

    public function test_shows_each_rates_cancellation_policy_when_show_rates_is_enabled()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        Rate::where('collection', 'pages')->where('slug', 'rate-a')->first()
            ->update(['cancellation_policy' => 'non_refundable']);
        $rateB = $this->createRateForEntry($entry, [
            'slug' => 'rate-b',
            'title' => 'Rate B',
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'rates' => true,
                'showRates' => true,
            ])
        );

        // Each booking button is a direct commitment to a rate — its policy must show beside it.
        $component->assertSee(trans('statamic-resrv::frontend.nonRefundable'));
        $component->assertSee(trans('statamic-resrv::frontend.freeCancellationUntilDate', [
            'date' => $this->date->copy()->subDays(7)->format('D d M Y'),
        ]));
    }

    public function test_shows_the_cancellation_policy_for_the_from_rate()
    {
        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        Rate::where('collection', 'pages')->where('slug', 'rate-a')->first()
            ->update(['cancellation_policy' => 'non_refundable']);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true])
        );

        $component->assertSee(trans('statamic-resrv::frontend.nonRefundable'));
    }

    public function test_orders_entries_by_collection_order_by_default()
    {
        // 'pages' has no custom sort, so the default 'order' sort falls back to title asc —
        // not Stache storage (creation) order, which would be Gamma, Alpha, Beta.
        $this->pagesEntry(price: 50, title: 'Gamma room');
        $this->pagesEntry(price: 30, title: 'Alpha room');
        $this->pagesEntry(price: 45, title: 'Beta room');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
        );

        $component->assertSeeInOrder(['Alpha room', 'Beta room', 'Gamma room']);
    }

    public function test_orders_dated_collections_newest_first_by_default()
    {
        // A dated collection's configured order is date desc; the default 'order' sort applies it.
        $collection = Collection::make('events')->routes('/{slug}')->dated(true);
        $collection->save();
        $this->makeBlueprint($collection);

        // Created oldest-first, so storage order is the opposite of the expected date-desc order.
        $oldest = $this->makeStatamicItemWithAvailability(collection: 'events', price: 50, title: 'Oldest event');
        $oldest->date(now()->subDays(3))->save();

        $middle = $this->makeStatamicItemWithAvailability(collection: 'events', price: 30, title: 'Middle event');
        $middle->date(now()->subDays(2))->save();

        $newest = $this->makeStatamicItemWithAvailability(collection: 'events', price: 45, title: 'Newest event');
        $newest->date(now()->subDay())->save();

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'events'])
        );

        $component->assertSeeInOrder(['Newest event', 'Middle event', 'Oldest event']);
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

    public function test_can_set_the_rate_sorting_parameter()
    {
        Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rateSorting' => 'price'])
            ->assertSet('rateSorting', 'price');
    }

    public function test_orders_each_entrys_rates_by_rate_order_by_default()
    {
        $this->entryWithCheaperHigherOrderRate();

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true, 'showRates' => true])
        );

        // order 0 (Base, 200.00) before order 1 (Shared, 180.00) — ordered by Rate.order, not price.
        $component->assertSeeInOrder(['200.00', '180.00']);
    }

    public function test_orders_each_entrys_rates_by_price_when_requested()
    {
        $this->entryWithCheaperHigherOrderRate();

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true, 'showRates' => true, 'rateSorting' => 'price'])
        );

        // Cheapest first: Shared (180.00) before Base (200.00).
        $component->assertSeeInOrder(['180.00', '200.00']);
    }

    public function test_unknown_rate_sorting_value_falls_back_to_order()
    {
        $this->entryWithCheaperHigherOrderRate();

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true, 'showRates' => true, 'rateSorting' => 'garbage'])
        );

        $component->assertSeeInOrder(['200.00', '180.00']);
    }

    public function test_get_available_orders_rates_by_order_or_price_per_entry()
    {
        [$entry, $baseRate, $sharedRate] = $this->entryWithCheaperHigherOrderRate();

        $data = [
            'date_start' => $this->date->toDateString(),
            'date_end' => $this->date->copy()->add(2, 'day')->toDateString(),
            'quantity' => 1,
        ];

        $ordered = app(Availability::class)->getAvailable($data, null, RateSorting::Order);
        $orderedRates = $ordered['data'][$entry->id()];

        // Both rates surface; the order-0 base rate leads under Order sorting.
        $this->assertEqualsCanonicalizing(
            [(string) $baseRate->id, (string) $sharedRate->id],
            $orderedRates->pluck('rate_id')->map(fn ($id) => (string) $id)->unique()->values()->all()
        );
        $this->assertEquals($baseRate->id, $orderedRates->first()['rate_id']);

        $priced = app(Availability::class)->getAvailable($data, null, RateSorting::Price);
        $pricedRates = $priced['data'][$entry->id()];

        // The cheaper (higher-order) shared rate leads under Price sorting.
        $this->assertEquals($sharedRate->id, $pricedRates->first()['rate_id']);
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
        // Route-less collection => entries have no URL, so select() books directly. Still
        // needs the blueprint so entries are mirrored into resrv_entries.
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

        // Configured with the ORIGIN id while browsing the Greek site: the query must
        // still resolve the localization (a different id) via its origin reference.
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

        // The English origin never appears in the Greek listing, so selecting it must be rejected.
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

        // Cutoff rules live on the resrv_entries mirror. A 48h cutoff before the searched
        // (tomorrow) date already lies in the past relative to now (today noon), so it blocks.
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

        // The searched dates fall inside the cutoff window, so the direct booking is blocked.
        $component->call('select', $entry->id(), $rateId)
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_keeps_pagination_visible_when_the_current_page_is_empty()
    {
        // Entries are paginated before availability is filtered. Titles force title-asc order
        // so the two sold-out entries land on page 1 (rendering empty) and the available one
        // on page 2 — the pagination links must still appear so page 2 is reachable.
        $this->pagesEntry(price: 50, available: 0, title: 'A sold out room');
        $this->pagesEntry(price: 30, available: 0, title: 'B sold out room');
        $this->pagesEntry(price: 45, available: 1, title: 'C available room');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'paginate' => 2])
        );

        // The current page is empty, but the pagination control still renders.
        $component->assertSee('No availability');
        $this->assertEquals(0, substr_count($component->html(), 'Book now'));

        $this->assertTrue($component->instance()->resolvedEntries()->hasPages());
        $component->assertSeeHtml('gotoPage(2');
    }

    public function test_excludes_private_scheduled_entries_from_the_listing()
    {
        // Dated collection where future-dated entries are private (scheduled).
        $collection = Collection::make('events')->routes('/{slug}')->dated(true)->futureDateBehavior('private');
        $collection->save();
        $this->makeBlueprint($collection);

        $public = $this->makeStatamicItemWithAvailability(collection: 'events', price: 50);
        $public->date(now()->subDay())->save();

        // Future date => private even though published === true.
        $scheduled = $this->makeStatamicItemWithAvailability(collection: 'events', price: 30);
        $scheduled->date(now()->addDays(10))->save();

        $fresh = Entry::find($scheduled->id());
        $this->assertTrue($fresh->published());
        $this->assertTrue($fresh->private());

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'events'])
        );

        // Only the public entry is listed.
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

        // A stale/forged call cannot book the hidden entry directly.
        $component->call('select', $scheduled->id())
            ->assertHasErrors('availability')
            ->assertNoRedirect();

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_surfaces_availability_errors_instead_of_an_empty_state()
    {
        // calculate_days_using_time adds a day for the later drop-off time, so a range the
        // form's duration rule accepts can still exceed the max — an error only the model raises.
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

        // Both date keys are present (hasDates() passes) but unparseable. Without the
        // searchIsValid guard, rows() would query and Carbon::parse() would throw on render.
        $component = Livewire::test(AvailabilityCollection::class, ['collection' => 'pages'])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => 'not-a-real-date',
                    'date_end' => 'also-not-a-real-date',
                ],
                'quantity' => 1,
                'rate' => null,
            ]);

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

        // select() called WITHOUT a rate: the resolved rate must be persisted, otherwise
        // rate_id saves as null and the availability decrement runs unscoped.
        $component->call('select', $entry->id())
            ->assertRedirect($checkout->url());

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entry->id(),
            'rate_id' => $rateId,
            'status' => 'pending',
        ]);
    }

    public function test_drops_a_rate_that_belongs_to_another_collection()
    {
        // A rate id from collection 'other' must not survive as a WHERE filter and empty the
        // 'pages' listing. Two pages rates => the dropped rate lands on null (no auto-select).
        $other = $this->makeStatamicItemWithAvailability(collection: 'other', price: 40);
        $foreignRateId = Rate::forEntry($other->id())->first()->id;

        $a = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $this->createRateForEntry($a, ['slug' => 'rate-b', 'title' => 'Rate B']);
        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 30, rateSlug: 'rate-a');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true]),
            rate: (string) $foreignRateId,
        );

        $component->assertSet('data.rate', null);
        $this->assertGreaterThan(0, substr_count($component->html(), 'Book now'));
    }

    public function test_drops_a_rate_restricted_to_an_entry_not_in_the_listing()
    {
        // Rate R is apply_to_all=false and linked only to A1, but the listing renders A2.
        $a1 = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $restricted = $this->createRateForEntry($a1, ['slug' => 'restricted', 'title' => 'Restricted', 'apply_to_all' => false]);
        $restricted->entries()->sync([$a1->id()]);

        $a2 = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 45, rateSlug: 'rate-a');

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['entries' => [$a2->id()], 'rates' => true]),
            rate: (string) $restricted->id,
        );

        // R is not valid for A2, so it is dropped; rate-a is the only remaining valid rate → auto-selected.
        $rateA = Rate::where('collection', 'pages')->where('slug', 'rate-a')->first();
        $component->assertSet('data.rate', (string) $rateA->id)
            ->assertSee('resrv-collection-'.$a2->id());
    }

    public function test_keeps_a_rate_valid_only_for_a_later_page_under_pagination()
    {
        // Titles force title-asc order; with paginate=2 the first page is A,B and C lands on page 2.
        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a', title: 'A room');
        $this->makeStatamicItemWithAvailability(collection: 'pages', price: 30, rateSlug: 'rate-a', title: 'B room');
        $c = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 45, rateSlug: 'rate-a', title: 'C room');

        // A restricted rate valid only for the page-2 entry C.
        $restricted = $this->createRateForEntry($c, ['slug' => 'page-two', 'title' => 'Page Two', 'apply_to_all' => false]);
        $restricted->entries()->sync([$c->id()]);

        // Viewing page 1 (A,B), seed the page-2-only rate. listingRateIds uses the full
        // unpaginated scope, so the rate is recognised as valid and NOT dropped.
        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'paginate' => 2,
                'sort' => 'title',
                'rates' => true,
            ]),
            rate: (string) $restricted->id,
        );

        $component->assertSet('data.rate', (string) $restricted->id);
    }

    public function test_drops_a_rate_for_a_cross_collection_entry_id_in_the_config()
    {
        // collection=pages but the entries list also names an out-of-collection id. That id
        // never passes scopedEntriesQuery (collection ∩ entries), so its rate is not valid here.
        $x1 = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $this->createRateForEntry($x1, ['slug' => 'rate-b', 'title' => 'Rate B']);

        $y = $this->makeStatamicItemWithAvailability(collection: 'other', price: 40);
        $yRateId = Rate::forEntry($y->id())->first()->id;

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, [
                'collection' => 'pages',
                'entries' => [$x1->id(), $y->id()],
                'rates' => true,
            ]),
            rate: (string) $yRateId,
        );

        $component->assertSet('data.rate', null)
            ->assertSee('resrv-collection-'.$x1->id());
    }

    public function test_drops_a_rate_assigned_only_to_a_private_scheduled_entry()
    {
        $collection = Collection::make('events')->routes('/{slug}')->dated(true)->futureDateBehavior('private');
        $collection->save();
        $this->makeBlueprint($collection);

        $public = $this->makeStatamicItemWithAvailability(collection: 'events', price: 50, rateSlug: 'rate-a');
        $public->date(now()->subDay())->save();
        $this->createRateForEntry($public, ['slug' => 'rate-b', 'title' => 'Rate B']);

        // A restricted rate linked only to a future-dated (private) entry.
        $scheduled = $this->makeStatamicItemWithAvailability(collection: 'events', price: 30, rateSlug: 'rate-a');
        $scheduled->date(now()->addDays(10))->save();
        $restricted = $this->createRateForEntry($scheduled, ['slug' => 'scheduled-only', 'title' => 'Scheduled Only', 'apply_to_all' => false]);
        $restricted->entries()->sync([$scheduled->id()]);

        $component = $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'events', 'rates' => true]),
            rate: (string) $restricted->id,
        );

        // The scheduled entry is excluded by whereStatus('published'), so its rate is dropped.
        $component->assertSet('data.rate', null)
            ->assertSee('resrv-collection-'.$public->id())
            ->assertDontSee('resrv-collection-'.$scheduled->id());
    }

    public function test_select_hands_off_the_chosen_rate_to_the_detail_page_results()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages', price: 50, rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B']);
        $this->createAvailabilityForEntry($entry, 30, 2, $rateB->id, 10);

        // Clicking a rate writes it into the shared session and redirects to the detail page.
        $this->search(
            Livewire::test(AvailabilityCollection::class, ['collection' => 'pages', 'rates' => true, 'showRates' => true])
        )->call('select', $entry->id(), $rateB->id)
            ->assertSet('data.rate', (string) $rateB->id)
            ->assertRedirect($entry->url());

        // The detail-page Results component restores the session and keeps the chosen rate.
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id(), 'rates' => true])
            ->assertSet('data.rate', (string) $rateB->id);
    }
}

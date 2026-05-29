<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityCollection;
use Reach\StatamicResrv\Models\Availability;
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
}

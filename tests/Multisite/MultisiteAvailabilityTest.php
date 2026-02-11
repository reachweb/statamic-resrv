<?php

namespace Reach\StatamicResrv\Tests\Multisite;

use Illuminate\Support\Str;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Tags\Collection\Collection as CollectionTag;

class MultisiteAvailabilityTest extends TestCase
{
    protected $date;

    protected $originEntry;

    protected $localizedEntry;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure multisite with English (default) and Greek
        Site::setSites([
            'en' => [
                'name' => 'English',
                'url' => 'http://localhost/',
                'locale' => 'en_US',
                'lang' => 'en',
            ],
            'el' => [
                'name' => 'Greek',
                'url' => 'http://localhost/el/',
                'locale' => 'el_GR',
                'lang' => 'el',
            ],
        ]);

        Site::setCurrent('en');

        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
    }

    protected function createMultisiteEntry(): void
    {
        // Create the collection with multisite enabled
        $collection = Collection::make('rooms')
            ->routes('/{slug}')
            ->sites(['en', 'el'])
            ->save();

        $this->makeBlueprint($collection);

        // Create the origin entry (English)
        $this->originEntry = Entry::make()
            ->collection('rooms')
            ->locale('en')
            ->slug('test-room')
            ->data([
                'title' => 'Test Room',
                'resrv_availability' => Str::random(6),
            ]);

        $this->originEntry->save();

        // Create the localized entry (Greek) - this will have a different ID
        $this->localizedEntry = $this->originEntry->makeLocalization('el');
        $this->localizedEntry->slug('test-room-el');
        $this->localizedEntry->data([
            'title' => 'Δοκιμαστικό Δωμάτιο',
            'resrv_availability' => $this->originEntry->get('resrv_availability'),
        ]);
        $this->localizedEntry->save();

        // Create a rate for the origin entry
        $rate = Rate::factory()->create([
            'statamic_id' => $this->originEntry->id(),
            'slug' => 'default',
            'title' => 'Default',
        ]);

        // Create availability records using the ORIGIN entry ID (this is how the system stores data)
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $this->originEntry->id(),
                'available' => 1,
                'price' => 100,
                'rate_id' => $rate->id,
            ]);
    }

    protected function makeBlueprint($collection): void
    {
        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Title',
                            ],
                        ],
                        [
                            'handle' => 'slug',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Slug',
                            ],
                        ],
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->setHandle($collection->handle())->setNamespace('collections.'.$collection->handle())->save();
    }

    /** @test */
    public function it_confirms_origin_and_localized_entries_have_different_ids()
    {
        $this->createMultisiteEntry();

        $this->assertNotEquals(
            $this->originEntry->id(),
            $this->localizedEntry->id(),
            'Origin and localized entries should have different IDs'
        );

        $this->assertTrue(
            $this->localizedEntry->hasOrigin(),
            'Localized entry should have an origin'
        );

        $this->assertEquals(
            $this->originEntry->id(),
            $this->localizedEntry->origin()->id(),
            'Localized entry origin should match the origin entry'
        );
    }

    /** @test */
    public function availability_is_stored_with_origin_entry_id()
    {
        $this->createMultisiteEntry();

        // Verify availability is stored with origin ID
        $availabilityCount = Availability::where('statamic_id', $this->originEntry->id())->count();
        $this->assertEquals(4, $availabilityCount);

        // Verify NO availability is stored with localized ID
        $localizedAvailabilityCount = Availability::where('statamic_id', $this->localizedEntry->id())->count();
        $this->assertEquals(0, $localizedAvailabilityCount);
    }

    /** @test */
    public function availability_results_component_resolves_localized_id_to_origin()
    {
        $this->createMultisiteEntry();

        // Switch to Greek site
        Site::setCurrent('el');

        // Mount the component with the LOCALIZED entry ID
        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->localizedEntry->id()])
            ->assertSet('entryId', $this->originEntry->id());

        // The entryId should be resolved to the ORIGIN entry ID
        $this->assertEquals(
            $this->originEntry->id(),
            $component->get('entryId'),
            'Component should resolve localized entry ID to origin entry ID'
        );
    }

    /** @test */
    public function availability_results_component_finds_availability_when_using_localized_entry()
    {
        $this->createMultisiteEntry();

        // Switch to Greek site
        Site::setCurrent('el');

        // Mount the component with the LOCALIZED entry ID and search for availability
        Livewire::test(AvailabilityResults::class, ['entry' => $this->localizedEntry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => '',
            ])
            ->assertHasNoErrors()
            ->assertSet('availability.data.price', '100.00');
    }

    /** @test */
    public function availability_results_component_finds_availability_when_using_origin_entry()
    {
        $this->createMultisiteEntry();

        // Stay on English site (default)
        Site::setCurrent('en');

        // Mount the component with the ORIGIN entry ID
        Livewire::test(AvailabilityResults::class, ['entry' => $this->originEntry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => '',
            ])
            ->assertHasNoErrors()
            ->assertSet('availability.data.price', '100.00');
    }

    /** @test */
    public function resrv_search_scope_filters_localized_entries_correctly()
    {
        $this->createMultisiteEntry();

        // Create another entry without availability for contrast
        $noAvailabilityEntry = Entry::make()
            ->collection('rooms')
            ->locale('en')
            ->slug('no-availability-room')
            ->data([
                'title' => 'No Availability Room',
                'resrv_availability' => Str::random(6),
            ]);
        $noAvailabilityEntry->save();

        // Create its localization
        $noAvailabilityLocalized = $noAvailabilityEntry->makeLocalization('el');
        $noAvailabilityLocalized->slug('no-availability-room-el');
        $noAvailabilityLocalized->data([
            'title' => 'Δωμάτιο χωρίς διαθεσιμότητα',
            'resrv_availability' => $noAvailabilityEntry->get('resrv_availability'),
        ]);
        $noAvailabilityLocalized->save();

        // Switch to Greek site
        Site::setCurrent('el');

        // Query localized entries (Greek)
        $query = Entry::query()
            ->where('collection', 'rooms')
            ->where('site', 'el');

        $beforeScope = $query->get()->pluck('id')->all();

        // Should have 2 localized entries
        $this->assertCount(2, $beforeScope);
        $this->assertContains($this->localizedEntry->id(), $beforeScope);
        $this->assertContains($noAvailabilityLocalized->id(), $beforeScope);

        // Apply the ResrvSearch scope
        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        // After scope: only the localized entry with availability should remain
        // This is the key test - the scope should correctly map origin IDs back to localized IDs
        $this->assertCount(1, $afterScope);
        $this->assertContains($this->localizedEntry->id(), $afterScope);
        $this->assertNotContains($noAvailabilityLocalized->id(), $afterScope);
    }

    /** @test */
    public function resrv_search_scope_works_on_default_site_with_origin_entries()
    {
        $this->createMultisiteEntry();

        // Create another entry without availability
        $noAvailabilityEntry = Entry::make()
            ->collection('rooms')
            ->locale('en')
            ->slug('no-availability-room')
            ->data([
                'title' => 'No Availability Room',
                'resrv_availability' => Str::random(6),
            ]);
        $noAvailabilityEntry->save();

        // Stay on English site (default)
        Site::setCurrent('en');

        // Query origin entries (English)
        $query = Entry::query()
            ->where('collection', 'rooms')
            ->where('site', 'en');

        $beforeScope = $query->get()->pluck('id')->all();

        $this->assertCount(2, $beforeScope);
        $this->assertContains($this->originEntry->id(), $beforeScope);
        $this->assertContains($noAvailabilityEntry->id(), $beforeScope);

        // Apply the ResrvSearch scope
        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        // After scope: only the entry with availability should remain
        $this->assertCount(1, $afterScope);
        $this->assertContains($this->originEntry->id(), $afterScope);
        $this->assertNotContains($noAvailabilityEntry->id(), $afterScope);
    }

    /** @test */
    public function resrv_search_scope_handles_quantity_on_multisite()
    {
        $this->createMultisiteEntry();

        // Create an entry with 2 available
        $twoAvailableEntry = Entry::make()
            ->collection('rooms')
            ->locale('en')
            ->slug('two-available-room')
            ->data([
                'title' => 'Two Available Room',
                'resrv_availability' => Str::random(6),
            ]);
        $twoAvailableEntry->save();

        $twoAvailableLocalized = $twoAvailableEntry->makeLocalization('el');
        $twoAvailableLocalized->slug('two-available-room-el');
        $twoAvailableLocalized->data([
            'title' => 'Δωμάτιο με δύο διαθέσιμα',
            'resrv_availability' => $twoAvailableEntry->get('resrv_availability'),
        ]);
        $twoAvailableLocalized->save();

        // Create a rate for the two-available entry
        $twoAvailableRate = Rate::factory()->create([
            'statamic_id' => $twoAvailableEntry->id(),
            'slug' => 'default',
            'title' => 'Default',
        ]);

        // Create availability with 2 available using ORIGIN ID
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $twoAvailableEntry->id(),
                'available' => 2,
                'price' => 150,
                'rate_id' => $twoAvailableRate->id,
            ]);

        // Switch to Greek site
        Site::setCurrent('el');

        // Query for quantity 2
        $query = Entry::query()
            ->where('collection', 'rooms')
            ->where('site', 'el');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 2,
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        // Only the entry with 2 available should be returned
        $this->assertCount(1, $afterScope);
        $this->assertContains($twoAvailableLocalized->id(), $afterScope);
        $this->assertNotContains($this->localizedEntry->id(), $afterScope);
    }

    /** @test */
    public function availability_hooks_attach_live_availability_to_localized_entries()
    {
        $this->createMultisiteEntry();

        // Switch to Greek site
        Site::setCurrent('el');

        // Use the collection tag with resrv_search scope
        $collectionTag = (new CollectionTag)
            ->setParser(Antlers::parser())
            ->setContext([]);

        $collectionTag->setParameters([
            'collection' => 'rooms',
            'site' => 'el',
            'query_scope' => 'resrv_search',
            'resrv_search:resrv_availability' => [
                'dates' => [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ],
            ],
        ]);

        $returnedEntries = $collectionTag->index();

        // Should return the localized entry
        $this->assertCount(1, $returnedEntries);
        $this->assertEquals($this->localizedEntry->id(), $returnedEntries->first()->id());

        // The live_availability should be attached to the localized entry
        // This is the key assertion: the hook should correctly map origin IDs to localized entries
        $this->assertArrayHasKey('live_availability', $returnedEntries->first()->toArray());
        $this->assertEquals('100.00', $returnedEntries->first()->get('live_availability')['price']);
    }

    /** @test */
    public function availability_hooks_attach_live_availability_to_origin_entries()
    {
        $this->createMultisiteEntry();

        // Stay on English site (default)
        Site::setCurrent('en');

        // Use the collection tag with resrv_search scope
        $collectionTag = (new CollectionTag)
            ->setParser(Antlers::parser())
            ->setContext([]);

        $collectionTag->setParameters([
            'collection' => 'rooms',
            'site' => 'en',
            'query_scope' => 'resrv_search',
            'resrv_search:resrv_availability' => [
                'dates' => [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ],
            ],
        ]);

        $returnedEntries = $collectionTag->index();

        // Should return the origin entry
        $this->assertCount(1, $returnedEntries);
        $this->assertEquals($this->originEntry->id(), $returnedEntries->first()->id());

        // The live_availability should be attached to the origin entry
        $this->assertArrayHasKey('live_availability', $returnedEntries->first()->toArray());
        $this->assertEquals('100.00', $returnedEntries->first()->get('live_availability')['price']);
    }
}

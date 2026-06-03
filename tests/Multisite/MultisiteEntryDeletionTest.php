<?php

namespace Reach\StatamicResrv\Tests\Multisite;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class MultisiteEntryDeletionTest extends TestCase
{
    protected $originEntry;

    protected $localizedEntry;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->signInAdmin();
    }

    protected function createMultisiteEntry(): Rate
    {
        $collection = Collection::make('rooms')
            ->routes('/{slug}')
            ->sites(['en', 'el'])
            ->save();

        $this->makeBlueprint($collection);

        $this->originEntry = Entry::make()
            ->collection('rooms')
            ->locale('en')
            ->slug('test-room')
            ->data([
                'title' => 'Test Room',
                'resrv_availability' => Str::random(6),
            ]);
        $this->originEntry->save();

        $this->localizedEntry = $this->originEntry->makeLocalization('el');
        $this->localizedEntry->slug('test-room-el');
        $this->localizedEntry->data([
            'title' => 'Δοκιμαστικό Δωμάτιο',
            'resrv_availability' => $this->originEntry->get('resrv_availability'),
        ]);
        $this->localizedEntry->save();

        $rate = Rate::factory()->create([
            'collection' => 'rooms',
            'slug' => 'default',
            'title' => 'Default',
        ]);

        // Availability is always stored against the origin id, not the localization id.
        Availability::factory()->create([
            'statamic_id' => $this->originEntry->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        return $rate;
    }

    protected function makeBlueprint($collection): void
    {
        Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                        ['handle' => 'slug', 'field' => ['type' => 'text', 'display' => 'Slug']],
                        ['handle' => 'resrv_availability', 'field' => ['type' => 'resrv_availability', 'display' => 'Resrv Availability']],
                    ],
                ],
            ],
        ])->setHandle($collection->handle())->setNamespace('collections.'.$collection->handle())->save();
    }

    protected function createActiveReservation(Rate $rate): void
    {
        Reservation::factory()->create([
            'item_id' => $this->originEntry->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function deleting_a_localization_of_a_booked_item_is_halted()
    {
        $rate = $this->createMultisiteEntry();
        $this->createActiveReservation($rate);

        $localizedId = $this->localizedEntry->id();

        // Statamic fires EntryDeleting with the localized id; the guard must resolve it to the origin.
        $result = $this->localizedEntry->delete();

        $this->assertFalse($result, 'Deleting a localization of a booked item should be halted by the EntryDeleting listener');
        $this->assertNotNull(Entry::find($localizedId), 'The localization should still exist after the blocked deletion');
    }

    #[Test]
    public function deleting_a_localization_proceeds_without_active_reservations()
    {
        $this->createMultisiteEntry();

        $localizedId = $this->localizedEntry->id();

        $result = $this->localizedEntry->delete();

        $this->assertNotFalse($result, 'A localization without active reservations should delete freely');
        $this->assertNull(Entry::find($localizedId), 'The localization should be gone after deletion');
    }

    #[Test]
    public function deleting_a_localization_keeps_the_origin_availability()
    {
        $this->createMultisiteEntry();

        $this->localizedEntry->delete();

        // The origin still exists, so its availability must survive.
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->originEntry->id(),
        ]);
    }

    // "Detach" makes a localization standalone without migrating Resrv data: the origin survives, the
    // detached entry starts empty.
    #[Test]
    public function detaching_localizations_keeps_origin_data_and_leaves_the_detached_entry_without_availability()
    {
        $rate = $this->createMultisiteEntry();
        $this->createActiveReservation($rate);

        $originId = $this->originEntry->id();
        $localizedId = $this->localizedEntry->id();

        $this->originEntry->detachLocalizations();

        // The booked origin's availability and reservation are untouched.
        $this->assertDatabaseHas('resrv_availabilities', ['statamic_id' => $originId]);
        $this->assertDatabaseHas('resrv_reservations', ['item_id' => $originId, 'status' => 'pending']);

        // The detached entry gets its own row but no availability.
        $this->assertDatabaseHas('resrv_entries', ['item_id' => $localizedId]);
        $this->assertDatabaseMissing('resrv_availabilities', ['statamic_id' => $localizedId]);
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Listeners;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Actions\DuplicateEntry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;

class NormalizeAvailabilityFieldValueTest extends TestCase
{
    use CreatesEntries;

    // Statamic's Duplicate action copies field values, leaving the original's ID in the duplicate.
    #[Test]
    public function regenerates_the_availability_field_value_when_an_entry_is_duplicated()
    {
        $this->signInAdmin();

        $original = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($original->blueprint());
        $original->set($handle, $original->id())->saveQuietly();

        (new DuplicateEntry)->run(collect([$original]), null);

        $duplicate = Entry::query()->where('duplicated_from', $original->id())->first();

        $this->assertNotNull($duplicate);
        $this->assertNotEquals($original->id(), $duplicate->get($handle));
        $this->assertEquals($duplicate->id(), $duplicate->get($handle));

        $this->assertIsArray($original->augmentedValue($handle)->value());
        $this->assertFalse($duplicate->augmentedValue($handle)->value());
    }

    // The repository owns ID generation (a Stache UUID, or the Eloquent driver's database
    // auto-increment) — assigning one here would insert a UUID into an integer primary key.
    #[Test]
    public function does_not_assign_an_id_to_a_new_entry_when_saving()
    {
        $existing = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($existing->blueprint());

        $entry = Entry::make()
            ->collection('pages')
            ->slug('a-new-entry')
            ->data(['title' => 'A new entry', $handle => $existing->id()]);

        EntrySaving::dispatch($entry);

        $this->assertNull($entry->id());
    }

    #[Test]
    public function normalizes_a_new_entry_created_with_another_entrys_id()
    {
        $existing = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($existing->blueprint());

        $entry = Entry::make()
            ->collection('pages')
            ->slug('a-programmatic-duplicate')
            ->data(['title' => 'A programmatic duplicate', $handle => $existing->id()]);

        $entry->save();

        $this->assertNotNull($entry->id());
        $this->assertEquals($entry->id(), $entry->get($handle));
        $this->assertEquals($entry->id(), Entry::find($entry->id())->get($handle));
    }

    #[Test]
    public function regenerates_a_stale_value_when_an_entry_is_saved()
    {
        $entry = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($entry->blueprint());

        $entry->set($handle, 'some-other-entry-id')->save();

        $this->assertEquals($entry->id(), Entry::find($entry->id())->get($handle));
    }

    // Saved-event subscribers (e.g. automatic Git) must never see the stale value.
    #[Test]
    public function normalizes_before_the_entry_is_written()
    {
        $entry = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($entry->blueprint());

        $seenAtSaving = null;
        Event::listen(EntrySaving::class, function (EntrySaving $event) use (&$seenAtSaving, $handle) {
            $seenAtSaving = $event->entry->get($handle);
        });

        $entry->set($handle, 'some-other-entry-id')->save();

        $this->assertEquals($entry->id(), $seenAtSaving);
    }

    #[Test]
    public function keeps_a_disabled_value_untouched()
    {
        $entry = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($entry->blueprint());

        $entry->set($handle, 'disabled')->save();

        $this->assertEquals('disabled', Entry::find($entry->id())->get($handle));
    }

    #[Test]
    public function keeps_the_entrys_own_id_untouched()
    {
        $entry = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($entry->blueprint());

        $entry->set($handle, $entry->id())->save();

        $this->assertEquals($entry->id(), Entry::find($entry->id())->get($handle));
    }

    #[Test]
    public function ignores_entries_without_an_availability_field()
    {
        $entry = $this->makeStatamicWithoutResrvAvailabilityField();

        $entry->set('title', 'Updated title')->save();

        $this->assertEquals('Updated title', Entry::find($entry->id())->get('title'));
    }
}

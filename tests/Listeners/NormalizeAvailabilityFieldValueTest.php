<?php

namespace Reach\StatamicResrv\Tests\Listeners;

use PHPUnit\Framework\Attributes\Test;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Actions\DuplicateEntry;
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

    #[Test]
    public function regenerates_a_stale_value_when_an_entry_is_saved()
    {
        $entry = $this->makeStatamicItemWithAvailability();
        $handle = AvailabilityField::getHandle($entry->blueprint());

        $entry->set($handle, 'some-other-entry-id')->save();

        $this->assertEquals($entry->id(), Entry::find($entry->id())->get($handle));
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

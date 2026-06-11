<?php

namespace Reach\StatamicResrv\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Fieldtypes\ResrvAvailability;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ResrvAvailabilityFieldtypeTest extends TestCase
{
    use CreatesEntries;

    // A stale value (e.g. from entry duplication) must not augment to another entry's availability.
    #[Test]
    public function augments_using_the_entry_id_even_when_the_stored_value_is_stale()
    {
        $original = $this->makeStatamicItemWithAvailability(price: 50);
        $duplicate = $this->makeStatamicItemWithAvailability(price: 25);

        $handle = AvailabilityField::getHandle($duplicate->blueprint());
        $duplicate->set($handle, $original->id())->saveQuietly();

        $augmented = $duplicate->augmentedValue($handle)->value();

        $this->assertIsArray($augmented);
        $this->assertEquals('25.00', $augmented['cheapest']);
        $this->assertCount(4, $augmented['data']);
    }

    #[Test]
    public function augments_to_false_when_the_entry_has_no_availability()
    {
        $entry = $this->makeStatamicItemWithAvailability();

        Availability::where('statamic_id', $entry->id())->delete();
        $handle = AvailabilityField::getHandle($entry->blueprint());
        $entry->set($handle, $entry->id())->saveQuietly();

        $this->assertFalse($entry->augmentedValue($handle)->value());
    }

    #[Test]
    public function augments_to_false_when_reservations_are_disabled()
    {
        $entry = $this->makeStatamicItemWithAvailability();

        $handle = AvailabilityField::getHandle($entry->blueprint());
        $entry->set($handle, 'disabled')->saveQuietly();

        $this->assertFalse($entry->augmentedValue($handle)->value());
    }

    #[Test]
    public function augments_by_value_when_there_is_no_entry_context()
    {
        $entry = $this->makeStatamicItemWithAvailability();

        $fieldtype = new ResrvAvailability;

        $this->assertIsArray($fieldtype->augment($entry->id()));
        $this->assertFalse($fieldtype->augment('disabled'));
        $this->assertFalse($fieldtype->augment(null));
    }
}

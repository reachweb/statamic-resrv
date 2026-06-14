<?php

namespace Reach\StatamicResrv\Tests\Option;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class OptionGlobalScopeTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The entry->collection cache is static and survives across tests in a process.
        Option::resetEntryCollectionCache();
    }

    public function test_apply_to_all_option_resolves_for_every_entry_in_its_collection()
    {
        $entryA = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $entryB = $this->makeStatamicItemWithAvailability(collection: 'pages');

        Option::factory()->create(['collection' => 'pages', 'apply_to_all' => true]);

        $this->assertTrue(Option::entry($entryA->id())->exists());
        $this->assertTrue(Option::entry($entryB->id())->exists());
    }

    public function test_per_entry_option_resolves_only_for_the_attached_entry()
    {
        $attached = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $other = $this->makeStatamicItemWithAvailability(collection: 'pages');

        // forEntry: collection derived + pivot row, apply_to_all stays false.
        Option::factory()->forEntry($attached->id())->create();

        $this->assertTrue(Option::entry($attached->id())->exists());
        $this->assertFalse(Option::entry($other->id())->exists());
    }

    public function test_options_are_isolated_per_collection()
    {
        $pagesEntry = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $carsEntry = $this->makeStatamicItemWithAvailability(collection: 'cars');

        Option::factory()->create(['collection' => 'pages', 'apply_to_all' => true]);

        $this->assertTrue(Option::entry($pagesEntry->id())->exists());
        $this->assertFalse(Option::entry($carsEntry->id())->exists());
    }

    public function test_unknown_entry_resolves_no_options()
    {
        Option::factory()->create(['collection' => 'pages', 'apply_to_all' => true]);

        $this->assertFalse(Option::entry('does-not-exist')->exists());
    }

    public function test_reorder_is_scoped_to_the_options_own_collection()
    {
        $this->makeStatamicItemWithAvailability(collection: 'pages');
        $this->makeStatamicItemWithAvailability(collection: 'cars');

        $pagesFirst = Option::factory()->create(['collection' => 'pages', 'order' => 1, 'slug' => 'pages-first']);
        $pagesSecond = Option::factory()->create(['collection' => 'pages', 'order' => 2, 'slug' => 'pages-second']);
        $carsFirst = Option::factory()->create(['collection' => 'cars', 'order' => 1, 'slug' => 'cars-first']);
        $carsSecond = Option::factory()->create(['collection' => 'cars', 'order' => 2, 'slug' => 'cars-second']);

        $pagesFirst->changeOrder(2);

        // The pages collection reshuffles...
        $this->assertEquals(2, $pagesFirst->fresh()->order);
        $this->assertEquals(1, $pagesSecond->fresh()->order);

        // ...but the cars collection is untouched.
        $this->assertEquals(1, $carsFirst->fresh()->order);
        $this->assertEquals(2, $carsSecond->fresh()->order);
    }

    // The whole point of the snapshot columns: editing a now-shared option must NOT retroactively
    // change the price charged on a reservation that was made before the edit.
    public function test_editing_a_shared_option_value_does_not_change_a_past_reservations_price()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages');

        $option = Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->forEntry($entry->id())
            ->create();
        $value = $option->values->first();

        $reservation = Reservation::factory()->create([
            'item_id' => $entry->id(),
            'price' => '100.00',
            'payment' => '100.00',
        ]);

        // Snapshot what was charged onto the pivot, as checkout does.
        $reservation->options()->attach($option->id, [
            'value' => $value->id,
            'value_name' => $value->name,
            'price' => '30.00',
            'price_type' => 'fixed',
        ]);

        // The admin later raises the (shared) value's price.
        $value->update(['price' => '999.00']);

        // The reservation still reflects the snapshotted 30.00, not the new 999.00.
        $this->assertEquals('30.00', $reservation->fresh()->extraCharges()->format());
    }

    // Pre-snapshot (historical) reservations have a null pivot price and must fall back to a live
    // re-price, preserving the behavior that existed before the snapshot columns were added.
    public function test_option_without_a_snapshot_falls_back_to_live_pricing()
    {
        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages');

        $option = Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->forEntry($entry->id())
            ->create();
        $value = $option->values->first();

        $reservation = Reservation::factory()->create([
            'item_id' => $entry->id(),
            'price' => '100.00',
            'payment' => '100.00',
        ]);

        // No snapshot columns — the legacy pivot shape.
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $value->update(['price' => '40.00']);

        // No snapshot → live re-price reflects the current value price.
        $this->assertEquals('40.00', $reservation->fresh()->extraCharges()->format());
    }
}

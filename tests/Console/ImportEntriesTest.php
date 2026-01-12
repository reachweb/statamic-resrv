<?php

namespace Reach\StatamicResrv\Tests\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Tests\TestCase;

class ImportEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_import_entries()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        // Entry is created by the EntrySaved listener, verify it exists
        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
        ]);

        // Delete it to test the import command
        Entry::where('item_id', $item->id())->forceDelete();

        $this->artisan('resrv:import-entries')
            ->expectsOutput('Resrv enabled entries imported to the database')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => $item->get('title'),
            'collection' => 'pages',
        ]);
    }

    public function test_import_entries_updates_existing_entry()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField([
            'title' => 'Original Title',
            'resrv_availability' => 'enabled',
        ]);

        // Entry is created by the EntrySaved listener
        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => 'Original Title',
        ]);

        // Update the database entry title directly to simulate out-of-sync state
        Entry::where('item_id', $item->id())->update(['title' => 'Outdated Title']);

        $this->artisan('resrv:import-entries')
            ->assertExitCode(0);

        // Title should be synced back from Statamic entry
        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => 'Original Title',
        ]);

        // Should only have one entry
        $this->assertEquals(1, Entry::where('item_id', $item->id())->count());
    }

    public function test_import_entries_restores_soft_deleted_entries()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField([
            'title' => 'Test Item',
            'resrv_availability' => 'enabled',
        ]);

        // Entry is created by the EntrySaved listener, soft delete it
        $entry = Entry::where('item_id', $item->id())->first();
        $entry->delete();

        // Verify it's soft deleted
        $this->assertSoftDeleted('resrv_entries', [
            'item_id' => $item->id(),
        ]);

        // Run the import command
        $this->artisan('resrv:import-entries')
            ->assertExitCode(0);

        // Should restore the entry
        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => 'Test Item',
            'deleted_at' => null,
        ]);

        // Should only have one entry (not a duplicate)
        $this->assertEquals(1, Entry::withTrashed()->where('item_id', $item->id())->count());
    }

    public function test_import_entries_ignores_entries_without_resrv_availability_field()
    {
        $item = $this->makeStatamicWithoutResrvAvailabilityField([
            'title' => 'Item Without Availability',
        ]);

        $this->artisan('resrv:import-entries')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_entries', [
            'item_id' => $item->id(),
        ]);
    }
}

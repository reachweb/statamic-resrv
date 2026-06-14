<?php

namespace Reach\StatamicResrv\Tests\Option;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Tests\TestCase;

class OptionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->revertToPreMigrationState();
    }

    /**
     * Roll back the finalize (restores item_id) and the data migration (clears collection + pivot),
     * leaving the 000001 schema (collection, apply_to_all, resrv_option_entries) in place so the data
     * migration can be exercised against legacy item_id rows. Mirrors RateMigrationTest.
     */
    protected function revertToPreMigrationState(): void
    {
        $finalize = include __DIR__.'/../../database/migrations/2026_06_13_000003_finalize_options_collection_migration.php';
        $finalize->down();

        $data = include __DIR__.'/../../database/migrations/2026_06_13_000002_migrate_options_to_collections.php';
        $data->down();

        DB::table('resrv_option_entries')->delete();
        DB::table('resrv_options')->delete();
        DB::table('resrv_entries')->delete();
    }

    protected function runDataMigration(): void
    {
        $migration = include __DIR__.'/../../database/migrations/2026_06_13_000002_migrate_options_to_collections.php';
        $migration->up();
    }

    protected function insertEntry(string $itemId, string $collection = 'pages'): void
    {
        DB::table('resrv_entries')->insert([
            'item_id' => $itemId,
            'title' => 'Entry '.$itemId,
            'enabled' => true,
            'collection' => $collection,
            'handle' => $collection,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function insertLegacyOption(string $itemId, array $overrides = []): int
    {
        return DB::table('resrv_options')->insertGetId(array_merge([
            'item_id' => $itemId,
            'name' => 'Option for '.($itemId ?: 'orphan'),
            'slug' => 'option-'.($itemId ?: 'orphan'),
            'description' => null,
            'order' => 1,
            'required' => false,
            'published' => true,
            'collection' => null,
            'apply_to_all' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_per_entry_option_gets_its_collection_and_a_single_pivot_row(): void
    {
        $this->insertEntry('entry-1', 'pages');
        $optionId = $this->insertLegacyOption('entry-1');

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_options', [
            'id' => $optionId,
            'collection' => 'pages',
            'apply_to_all' => false,
        ]);

        $this->assertDatabaseHas('resrv_option_entries', [
            'option_id' => $optionId,
            'statamic_id' => 'entry-1',
        ]);
        $this->assertEquals(1, DB::table('resrv_option_entries')->where('option_id', $optionId)->count());
    }

    public function test_each_option_keeps_its_own_entry_within_a_collection(): void
    {
        $this->insertEntry('entry-a', 'pages');
        $this->insertEntry('entry-b', 'pages');

        $optionA = $this->insertLegacyOption('entry-a', ['slug' => 'option-a']);
        $optionB = $this->insertLegacyOption('entry-b', ['slug' => 'option-b']);

        $this->runDataMigration();

        // Both land in the same collection but stay attached only to their own entry (apply_to_all=false).
        $this->assertDatabaseHas('resrv_option_entries', ['option_id' => $optionA, 'statamic_id' => 'entry-a']);
        $this->assertDatabaseHas('resrv_option_entries', ['option_id' => $optionB, 'statamic_id' => 'entry-b']);
        $this->assertDatabaseMissing('resrv_option_entries', ['option_id' => $optionA, 'statamic_id' => 'entry-b']);
        $this->assertEquals(0, DB::table('resrv_options')->where('apply_to_all', true)->count());
    }

    public function test_soft_deleted_option_is_migrated_too(): void
    {
        $this->insertEntry('entry-2', 'cars');
        $optionId = $this->insertLegacyOption('entry-2', ['deleted_at' => now()]);

        $this->runDataMigration();

        // The data migration uses a raw query that bypasses SoftDeletes, so shared/historical options
        // referenced by past reservations are migrated like any other.
        $this->assertDatabaseHas('resrv_options', [
            'id' => $optionId,
            'collection' => 'cars',
        ]);
        $this->assertDatabaseHas('resrv_option_entries', [
            'option_id' => $optionId,
            'statamic_id' => 'entry-2',
        ]);
    }

    public function test_option_with_empty_item_id_is_left_unattached(): void
    {
        $optionId = $this->insertLegacyOption('');

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_options', [
            'id' => $optionId,
            'collection' => null,
            'apply_to_all' => false,
        ]);
        $this->assertEquals(0, DB::table('resrv_option_entries')->where('option_id', $optionId)->count());
    }

    public function test_option_pointing_at_a_missing_entry_is_inert_but_keeps_a_pivot_row(): void
    {
        // item_id set but no matching resrv_entries row (the entry was deleted). The migration still
        // writes a resrv_option_entries pivot for any non-empty item_id, but the option is inert: its
        // null collection makes Option::scopeEntry() never surface it.
        $optionId = $this->insertLegacyOption('ghost-entry');

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_options', [
            'id' => $optionId,
            'collection' => null,
        ]);

        // A (dangling) pivot row is still created — document the actual behavior, not the "unattached" framing.
        $this->assertDatabaseHas('resrv_option_entries', [
            'option_id' => $optionId,
            'statamic_id' => 'ghost-entry',
        ]);
        $this->assertEquals(1, DB::table('resrv_option_entries')->where('option_id', $optionId)->count());
    }

    public function test_running_the_data_migration_twice_is_idempotent(): void
    {
        $this->insertEntry('entry-3', 'pages');
        $optionId = $this->insertLegacyOption('entry-3');

        $this->runDataMigration();
        $this->runDataMigration();

        // The pivot insert is guarded by an existence check, so a re-run creates no duplicate rows.
        $this->assertEquals(1, DB::table('resrv_option_entries')->where('option_id', $optionId)->count());
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Tests\TestCase;

class RateMigrationTest extends TestCase
{
    /**
     * Override DatabaseMigrations to skip rollback registration.
     * We manually manipulate migration state and SQLite in-memory
     * is destroyed automatically after each test.
     */
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->revertToPreMigrationState();
    }

    protected function revertToPreMigrationState(): void
    {
        $finalize = include __DIR__.'/../../database/migrations/2026_03_01_000004_finalize_rate_migration.php';
        $finalize->down();

        $dataMigration = include __DIR__.'/../../database/migrations/2026_03_01_000003_migrate_properties_to_rates.php';
        $dataMigration->down();

        DB::table('resrv_availabilities')->delete();
        DB::table('resrv_reservations')->delete();
        DB::table('resrv_child_reservations')->delete();
        DB::table('resrv_fixed_pricing')->delete();
        DB::table('resrv_entries')->delete();
    }

    protected function runDataMigration(): void
    {
        $migration = include __DIR__.'/../../database/migrations/2026_03_01_000003_migrate_properties_to_rates.php';
        $migration->up();
    }

    protected function insertEntry(string $statamicId, string $collection = 'rooms'): void
    {
        DB::table('resrv_entries')->insert([
            'item_id' => $statamicId,
            'title' => 'Entry '.$statamicId,
            'enabled' => true,
            'collection' => $collection,
            'handle' => $collection,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_entry_with_none_property_gets_default_rate(): void
    {
        $this->insertEntry('entry-1');
        $this->insertAvailability('entry-1', 'none', 50, 1, now()->toDateString());

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_rates', [
            'collection' => 'rooms',
            'slug' => 'default',
            'title' => 'Default',
        ]);

        $this->assertEquals(1, DB::table('resrv_rates')->where('collection', 'rooms')->count());
    }

    public function test_entries_in_same_collection_share_rates(): void
    {
        $this->insertEntry('entry-2a', 'hotel');
        $this->insertEntry('entry-2b', 'hotel');
        $this->insertAvailability('entry-2a', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-2b', 'double-room', 120, 1, now()->addDay()->toDateString());
        $this->insertAvailability('entry-2a', 'single-room', 75, 3, now()->addDay()->toDateString());

        $this->runDataMigration();

        // Should have 2 rates for the 'hotel' collection, not 3 (double-room shared)
        $rates = DB::table('resrv_rates')->where('collection', 'hotel')->orderBy('order')->get();

        $this->assertCount(2, $rates);
        $this->assertEquals('double-room', $rates[0]->slug);
        $this->assertEquals('single-room', $rates[1]->slug);
    }

    public function test_availability_records_mapped_to_correct_rate_ids(): void
    {
        $this->insertEntry('entry-3');
        $this->insertAvailability('entry-3', 'standard', 80, 2, now()->toDateString());
        $this->insertAvailability('entry-3', 'standard', 80, 2, now()->addDay()->toDateString());
        $this->insertAvailability('entry-3', 'premium', 120, 1, now()->toDateString());

        $this->runDataMigration();

        $standardRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'standard')
            ->first();

        $premiumRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'premium')
            ->first();

        $this->assertEquals(
            2,
            DB::table('resrv_availabilities')
                ->where('rate_id', $standardRate->id)
                ->count()
        );

        $this->assertEquals(
            1,
            DB::table('resrv_availabilities')
                ->where('rate_id', $premiumRate->id)
                ->count()
        );

        $this->assertEquals(
            0,
            DB::table('resrv_availabilities')
                ->whereNull('rate_id')
                ->count()
        );
    }

    public function test_reservation_property_values_mapped_to_rate_ids(): void
    {
        $this->insertEntry('entry-4');
        $this->insertAvailability('entry-4', 'deluxe', 150, 2, now()->toDateString());
        $this->insertReservation('entry-4', 'deluxe');
        $this->insertReservation('entry-4', 'deluxe');

        $this->runDataMigration();

        $deluxeRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'deluxe')
            ->first();

        $this->assertEquals(
            2,
            DB::table('resrv_reservations')
                ->where('rate_id', $deluxeRate->id)
                ->count()
        );
    }

    public function test_child_reservation_property_values_mapped_to_rate_ids(): void
    {
        $this->insertEntry('entry-5');
        $this->insertAvailability('entry-5', 'cabin', 90, 1, now()->toDateString());
        $reservationId = $this->insertReservation('entry-5', 'cabin');
        $this->insertChildReservation($reservationId, 'cabin');

        $this->runDataMigration();

        $cabinRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'cabin')
            ->first();

        $this->assertEquals(
            1,
            DB::table('resrv_child_reservations')
                ->where('rate_id', $cabinRate->id)
                ->count()
        );
    }

    public function test_fixed_pricing_gets_rate_id_assigned(): void
    {
        $this->insertEntry('entry-6');
        $this->insertAvailability('entry-6', 'none', 50, 1, now()->toDateString());
        $this->insertFixedPricing('entry-6');

        $this->runDataMigration();

        $defaultRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'default')
            ->first();

        // Single rate — one fixed pricing row assigned to it
        $fixedRows = DB::table('resrv_fixed_pricing')
            ->where('statamic_id', 'entry-6')
            ->get();

        $this->assertCount(1, $fixedRows);
        $this->assertEquals($defaultRate->id, $fixedRows->first()->rate_id);
    }

    public function test_fixed_pricing_assigned_to_first_rate_before_finalize(): void
    {
        $this->insertEntry('entry-fp');
        $this->insertAvailability('entry-fp', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-fp', 'single-room', 75, 3, now()->toDateString());
        $this->insertFixedPricing('entry-fp');

        $this->runDataMigration();

        $rates = DB::table('resrv_rates')->where('collection', 'rooms')->orderBy('order')->get();
        $this->assertCount(2, $rates);

        // Before finalize, fixed pricing is assigned to the first rate only
        $fixedPricingRows = DB::table('resrv_fixed_pricing')
            ->where('statamic_id', 'entry-fp')
            ->get();

        $this->assertCount(1, $fixedPricingRows);
        $this->assertEquals($rates->first()->id, $fixedPricingRows->first()->rate_id);
    }

    public function test_fixed_pricing_duplicated_across_all_rates_after_finalize(): void
    {
        $this->insertEntry('entry-fp2');
        $this->insertAvailability('entry-fp2', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-fp2', 'single-room', 75, 3, now()->toDateString());
        $this->insertFixedPricing('entry-fp2');

        $this->runDataMigration();

        $rates = DB::table('resrv_rates')->where('collection', 'rooms')->orderBy('order')->get();
        $this->assertCount(2, $rates);

        // Run finalize — should duplicate fixed pricing across all rates
        $finalize = include __DIR__.'/../../database/migrations/2026_03_01_000004_finalize_rate_migration.php';
        $finalize->up();

        $fixedPricingRows = DB::table('resrv_fixed_pricing')
            ->where('statamic_id', 'entry-fp2')
            ->get();

        $this->assertCount(2, $fixedPricingRows);
        $this->assertEquals(
            $rates->pluck('id')->sort()->values()->toArray(),
            $fixedPricingRows->pluck('rate_id')->sort()->values()->toArray()
        );

        // Each row should have the same price
        foreach ($fixedPricingRows as $row) {
            $this->assertEquals(45.00, $row->price);
        }
    }

    public function test_fixed_pricing_not_duplicated_for_entry_scoped_rates_that_dont_apply(): void
    {
        // entry-aa has both double-room and single-room; entry-bb only has double-room
        $this->insertEntry('entry-aa', 'hotel');
        $this->insertEntry('entry-bb', 'hotel');
        $this->insertAvailability('entry-aa', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-aa', 'single-room', 75, 3, now()->toDateString());
        $this->insertAvailability('entry-bb', 'double-room', 120, 1, now()->toDateString());

        $this->insertFixedPricing('entry-aa');
        $this->insertFixedPricing('entry-bb');

        $this->runDataMigration();

        $doubleRoom = DB::table('resrv_rates')->where('collection', 'hotel')->where('slug', 'double-room')->first();
        $singleRoom = DB::table('resrv_rates')->where('collection', 'hotel')->where('slug', 'single-room')->first();

        // double-room is apply_to_all, single-room is entry-scoped to entry-aa only
        $this->assertTrue((bool) $doubleRoom->apply_to_all);
        $this->assertFalse((bool) $singleRoom->apply_to_all);

        $finalize = include __DIR__.'/../../database/migrations/2026_03_01_000004_finalize_rate_migration.php';
        $finalize->up();

        // double-room (apply_to_all): should have fixed pricing for both entries
        $doubleRoomFixed = DB::table('resrv_fixed_pricing')->where('rate_id', $doubleRoom->id)->get();
        $this->assertCount(2, $doubleRoomFixed);
        $this->assertEqualsCanonicalizing(
            ['entry-aa', 'entry-bb'],
            $doubleRoomFixed->pluck('statamic_id')->toArray()
        );

        // single-room (entry-scoped): should only have fixed pricing for entry-aa, NOT entry-bb
        $singleRoomFixed = DB::table('resrv_fixed_pricing')->where('rate_id', $singleRoom->id)->get();
        $this->assertCount(1, $singleRoomFixed);
        $this->assertEquals('entry-aa', $singleRoomFixed->first()->statamic_id);
    }

    public function test_migration_is_idempotent(): void
    {
        $this->insertEntry('entry-7');
        $this->insertAvailability('entry-7', 'room-a', 100, 1, now()->toDateString());
        $this->insertAvailability('entry-7', 'room-b', 80, 2, now()->addDay()->toDateString());

        $this->runDataMigration();

        $ratesAfterFirst = DB::table('resrv_rates')->where('collection', 'rooms')->count();
        $mappedAfterFirst = DB::table('resrv_availabilities')->whereNotNull('rate_id')->count();

        // Run again — should not create duplicates
        $this->runDataMigration();

        $ratesAfterSecond = DB::table('resrv_rates')->where('collection', 'rooms')->count();
        $mappedAfterSecond = DB::table('resrv_availabilities')->whereNotNull('rate_id')->count();

        $this->assertEquals($ratesAfterFirst, $ratesAfterSecond);
        $this->assertEquals($mappedAfterFirst, $mappedAfterSecond);
    }

    public function test_rates_created_with_correct_defaults(): void
    {
        $this->insertEntry('entry-8');
        $this->insertAvailability('entry-8', 'villa', 300, 1, now()->toDateString());

        $this->runDataMigration();

        $rate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'villa')
            ->first();

        $this->assertEquals('independent', $rate->pricing_type);
        $this->assertEquals('independent', $rate->availability_type);
        $this->assertTrue((bool) $rate->refundable);
        $this->assertTrue((bool) $rate->published);
        $this->assertTrue((bool) $rate->apply_to_all);
        $this->assertNotNull($rate->created_at);
        $this->assertNotNull($rate->updated_at);
    }

    public function test_apply_to_all_false_when_properties_differ_across_entries(): void
    {
        $this->insertEntry('entry-aa', 'hotel');
        $this->insertEntry('entry-bb', 'hotel');
        $this->insertAvailability('entry-aa', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-aa', 'single-room', 75, 3, now()->toDateString());
        $this->insertAvailability('entry-bb', 'double-room', 120, 1, now()->toDateString());

        $this->runDataMigration();

        $doubleRoom = DB::table('resrv_rates')
            ->where('collection', 'hotel')
            ->where('slug', 'double-room')
            ->first();

        $singleRoom = DB::table('resrv_rates')
            ->where('collection', 'hotel')
            ->where('slug', 'single-room')
            ->first();

        // double-room: both entries have it → apply_to_all = true
        $this->assertTrue((bool) $doubleRoom->apply_to_all);
        $this->assertEquals(0, DB::table('resrv_rate_entries')->where('rate_id', $doubleRoom->id)->count());

        // single-room: only entry-aa has it → apply_to_all = false with pivot
        $this->assertFalse((bool) $singleRoom->apply_to_all);
        $this->assertEquals(1, DB::table('resrv_rate_entries')->where('rate_id', $singleRoom->id)->count());
        $this->assertDatabaseHas('resrv_rate_entries', [
            'rate_id' => $singleRoom->id,
            'statamic_id' => 'entry-aa',
        ]);
    }

    public function test_null_property_reservations_get_default_rate(): void
    {
        $this->insertEntry('entry-np1');
        $this->insertAvailability('entry-np1', 'none', 50, 1, now()->toDateString());
        $this->insertReservation('entry-np1');

        $this->runDataMigration();

        $defaultRate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'default')
            ->first();

        $this->assertNotNull($defaultRate);
        $this->assertEquals(
            1,
            DB::table('resrv_reservations')
                ->where('rate_id', $defaultRate->id)
                ->count()
        );
        $this->assertEquals(0, DB::table('resrv_reservations')->whereNull('rate_id')->count());
    }

    public function test_null_property_child_reservations_get_default_rate(): void
    {
        $this->insertEntry('entry-np2');
        $this->insertAvailability('entry-np2', 'none', 50, 1, now()->toDateString());
        $reservationId = $this->insertReservation('entry-np2');
        $this->insertChildReservation($reservationId);

        $this->runDataMigration();

        $this->assertEquals(0, DB::table('resrv_reservations')->whereNull('rate_id')->count());
        $this->assertEquals(0, DB::table('resrv_child_reservations')->whereNull('rate_id')->count());
    }

    public function test_finalize_migration_handles_orphaned_availability_rows(): void
    {
        // Insert availability with no matching resrv_entries row (orphan)
        $this->insertAvailability('orphan-entry', 'none', 50, 1, now()->toDateString());

        // Also insert a valid entry so migration 3 has work to do
        $this->insertEntry('valid-entry');
        $this->insertAvailability('valid-entry', 'none', 60, 1, now()->toDateString());

        $this->runDataMigration();

        // The orphan should still have null rate_id (no matching entry in resrv_entries)
        $this->assertEquals(
            1,
            DB::table('resrv_availabilities')->whereNull('rate_id')->count()
        );

        // Run finalize migration — should clean up orphans and not throw
        $finalize = include __DIR__.'/../../database/migrations/2026_03_01_000004_finalize_rate_migration.php';
        $finalize->up();

        // Orphan should be deleted, valid entry should remain
        $this->assertEquals(0, DB::table('resrv_availabilities')->whereNull('rate_id')->count());
        $this->assertEquals(1, DB::table('resrv_availabilities')->count());
    }

    public function test_reservation_only_property_gets_rate_created(): void
    {
        $this->insertEntry('entry-ro1');
        // No availability for 'suite', only a reservation
        $this->insertReservation('entry-ro1', 'suite');

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_rates', [
            'collection' => 'rooms',
            'slug' => 'suite',
        ]);

        $rate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'suite')
            ->first();

        $this->assertEquals(
            1,
            DB::table('resrv_reservations')
                ->where('rate_id', $rate->id)
                ->count()
        );
    }

    public function test_reservation_only_property_reuses_rate_from_availability(): void
    {
        $this->insertEntry('entry-ro2');
        // Availability creates the 'deluxe' rate
        $this->insertAvailability('entry-ro2', 'deluxe', 150, 2, now()->toDateString());
        // Reservation for same property but no matching availability date
        $this->insertReservation('entry-ro2', 'deluxe');

        $this->runDataMigration();

        // Should be exactly 1 rate, not 2
        $this->assertEquals(
            1,
            DB::table('resrv_rates')
                ->where('collection', 'rooms')
                ->where('slug', 'deluxe')
                ->count()
        );

        // Both availability and reservation should point to the same rate
        $rate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'deluxe')
            ->first();

        $this->assertEquals(0, DB::table('resrv_reservations')->whereNull('rate_id')->count());
        $this->assertEquals(0, DB::table('resrv_availabilities')->whereNull('rate_id')->count());
        $this->assertEquals($rate->id, DB::table('resrv_reservations')->first()->rate_id);
    }

    public function test_child_reservation_gets_rate_from_reservation_only_property(): void
    {
        $this->insertEntry('entry-ro3');
        $reservationId = $this->insertReservation('entry-ro3', 'cabin');
        $this->insertChildReservation($reservationId, 'cabin');

        $this->runDataMigration();

        $rate = DB::table('resrv_rates')
            ->where('collection', 'rooms')
            ->where('slug', 'cabin')
            ->first();

        $this->assertNotNull($rate);
        $this->assertEquals(
            1,
            DB::table('resrv_child_reservations')
                ->where('rate_id', $rate->id)
                ->count()
        );
    }

    public function test_finalize_succeeds_when_reservation_property_has_no_availability(): void
    {
        $this->insertEntry('entry-ro4');
        $this->insertReservation('entry-ro4', 'archive');

        $this->runDataMigration();

        // Should not throw when making rate_id NOT NULL
        $finalize = include __DIR__.'/../../database/migrations/2026_03_01_000004_finalize_rate_migration.php';
        $finalize->up();

        $this->assertEquals(0, DB::table('resrv_reservations')->whereNull('rate_id')->count());
    }

    protected function insertAvailability(string $statamicId, string $property, float $price, int $available, string $date): int
    {
        return DB::table('resrv_availabilities')->insertGetId([
            'statamic_id' => $statamicId,
            'date' => $date,
            'price' => $price,
            'available' => $available,
            'property' => $property,
            'rate_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertReservation(string $itemId, ?string $property = null): int
    {
        return DB::table('resrv_reservations')->insertGetId([
            'status' => 'confirmed',
            'type' => 'normal',
            'reference' => 'TEST-'.uniqid(),
            'item_id' => $itemId,
            'date_start' => now(),
            'date_end' => now()->addDays(2),
            'quantity' => 1,
            'price' => 100,
            'payment' => 100,
            'payment_id' => 'test_'.uniqid(),
            'property' => $property,
            'rate_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertChildReservation(int $reservationId, ?string $property = null): int
    {
        return DB::table('resrv_child_reservations')->insertGetId([
            'reservation_id' => $reservationId,
            'date_start' => now(),
            'date_end' => now()->addDays(2),
            'quantity' => 1,
            'property' => $property,
            'rate_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertFixedPricing(string $statamicId): int
    {
        return DB::table('resrv_fixed_pricing')->insertGetId([
            'statamic_id' => $statamicId,
            'days' => '{"1": "45.00"}',
            'price' => 45.00,
            'rate_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

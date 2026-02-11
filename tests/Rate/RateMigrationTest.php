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

        $this->assertEquals(
            $defaultRate->id,
            DB::table('resrv_fixed_pricing')
                ->where('statamic_id', 'entry-6')
                ->first()
                ->rate_id
        );
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

    protected function insertReservation(string $itemId, string $property): int
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

    protected function insertChildReservation(int $reservationId, string $property): int
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

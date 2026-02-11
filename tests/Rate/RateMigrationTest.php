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
    }

    protected function runDataMigration(): void
    {
        $migration = include __DIR__.'/../../database/migrations/2026_03_01_000003_migrate_properties_to_rates.php';
        $migration->up();
    }

    public function test_entry_with_none_property_gets_default_rate(): void
    {
        $this->insertAvailability('entry-1', 'none', 50, 1, now()->toDateString());

        $this->runDataMigration();

        $this->assertDatabaseHas('resrv_rates', [
            'statamic_id' => 'entry-1',
            'slug' => 'default',
            'title' => 'Default',
        ]);

        $this->assertEquals(1, DB::table('resrv_rates')->where('statamic_id', 'entry-1')->count());
    }

    public function test_entry_with_multiple_properties_gets_multiple_rates(): void
    {
        $this->insertAvailability('entry-2', 'double-room', 100, 2, now()->toDateString());
        $this->insertAvailability('entry-2', 'single-room', 75, 3, now()->addDay()->toDateString());
        $this->insertAvailability('entry-2', 'suite', 200, 1, now()->addDays(2)->toDateString());

        $this->runDataMigration();

        $rates = DB::table('resrv_rates')->where('statamic_id', 'entry-2')->orderBy('order')->get();

        $this->assertCount(3, $rates);
        $this->assertEquals('double-room', $rates[0]->slug);
        $this->assertEquals('single-room', $rates[1]->slug);
        $this->assertEquals('suite', $rates[2]->slug);

        $this->assertEquals(0, $rates[0]->order);
        $this->assertEquals(1, $rates[1]->order);
        $this->assertEquals(2, $rates[2]->order);
    }

    public function test_availability_records_mapped_to_correct_rate_ids(): void
    {
        $this->insertAvailability('entry-3', 'standard', 80, 2, now()->toDateString());
        $this->insertAvailability('entry-3', 'standard', 80, 2, now()->addDay()->toDateString());
        $this->insertAvailability('entry-3', 'premium', 120, 1, now()->toDateString());

        $this->runDataMigration();

        $standardRate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-3')
            ->where('slug', 'standard')
            ->first();

        $premiumRate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-3')
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
        $this->insertAvailability('entry-4', 'deluxe', 150, 2, now()->toDateString());
        $this->insertReservation('entry-4', 'deluxe');
        $this->insertReservation('entry-4', 'deluxe');

        $this->runDataMigration();

        $deluxeRate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-4')
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
        $this->insertAvailability('entry-5', 'cabin', 90, 1, now()->toDateString());
        $reservationId = $this->insertReservation('entry-5', 'cabin');
        $this->insertChildReservation($reservationId, 'cabin');

        $this->runDataMigration();

        $cabinRate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-5')
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
        $this->insertAvailability('entry-6', 'none', 50, 1, now()->toDateString());
        $this->insertFixedPricing('entry-6');

        $this->runDataMigration();

        $defaultRate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-6')
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
        $this->insertAvailability('entry-7', 'room-a', 100, 1, now()->toDateString());
        $this->insertAvailability('entry-7', 'room-b', 80, 2, now()->addDay()->toDateString());

        $this->runDataMigration();

        $ratesAfterFirst = DB::table('resrv_rates')->where('statamic_id', 'entry-7')->count();
        $mappedAfterFirst = DB::table('resrv_availabilities')->whereNotNull('rate_id')->count();

        // Run again — should not create duplicates
        $this->runDataMigration();

        $ratesAfterSecond = DB::table('resrv_rates')->where('statamic_id', 'entry-7')->count();
        $mappedAfterSecond = DB::table('resrv_availabilities')->whereNotNull('rate_id')->count();

        $this->assertEquals($ratesAfterFirst, $ratesAfterSecond);
        $this->assertEquals($mappedAfterFirst, $mappedAfterSecond);
    }

    public function test_rates_created_with_correct_defaults(): void
    {
        $this->insertAvailability('entry-8', 'villa', 300, 1, now()->toDateString());

        $this->runDataMigration();

        $rate = DB::table('resrv_rates')
            ->where('statamic_id', 'entry-8')
            ->where('slug', 'villa')
            ->first();

        $this->assertEquals('independent', $rate->pricing_type);
        $this->assertEquals('independent', $rate->availability_type);
        $this->assertTrue((bool) $rate->refundable);
        $this->assertTrue((bool) $rate->published);
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

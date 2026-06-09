<?php

namespace Reach\StatamicResrv\Tests\Caching;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Helpers\DataImport;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Fields\Field;

/**
 * Regression coverage for Laravel 13's `cache.serializable_classes => false` default, which
 * makes serializing cache stores return cached OBJECTS as `__PHP_Incomplete_Class` on warm
 * reads. The suite normally uses the non-serializing `array` store, hiding this.
 */
class CacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    // Mirror a hardened install: serialize the in-memory array store and apply
    // allowed_classes. Statamic then upgrades `false` to its partial allow-list at boot.
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array.serialize', true);
        $app['config']->set('cache.serializable_classes', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_the_test_environment_runs_a_serializing_store_with_allowed_classes_hardening(): void
    {
        // An unregistered object must come back incomplete, or the rest proves nothing.
        Cache::put('resrv_cache_hardening_probe', new UnregisteredCacheFixture('resrv'), 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('resrv_cache_hardening_probe'));
    }

    public function test_the_availability_field_survives_a_warm_serializing_cache_read(): void
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $blueprint = $entry->blueprint();

        AvailabilityField::getField($blueprint); // cold: writes to the store

        $field = AvailabilityField::getField($blueprint); // warm: re-read with allowed_classes

        $this->assertInstanceOf(Field::class, $field);
        $this->assertSame('resrv_availability', $field->type());
    }

    public function test_syncing_an_entry_to_the_database_works_on_a_warm_field_cache(): void
    {
        // Reported symptom: CP saves call syncToDatabase(), which uses the cached Field.
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        Cache::flush();

        $resrvEntry = new ResrvEntry;

        $resrvEntry->syncToDatabase($entry); // cold
        $resrvEntry->syncToDatabase($entry); // warm: incomplete Field's ->handle() would throw

        $this->assertDatabaseHas('resrv_entries', ['item_id' => $entry->id()]);
    }

    public function test_dynamic_pricing_discounts_survive_a_warm_serializing_cache_read(): void
    {
        $entry = $this->makeStatamicItem();

        // Default factory: 20% off for reservations of 3+ days within the date window.
        $dynamic = DynamicPricing::factory()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $entry->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();

        $price = Price::create('100.00');
        $start = today()->setTime(12, 0, 0);
        $end = today()->addDays(3)->setTime(12, 0, 0);
        $duration = 3;

        $cold = DynamicPricing::searchForAvailability($entry->id, $price, $start, $end, $duration);
        $this->assertNotFalse($cold);
        $this->assertSame('80.00', $cold->apply(clone $price)->format());

        // Warm: incomplete stdClass rows read as null, so the discount is silently dropped.
        $warm = DynamicPricing::searchForAvailability($entry->id, $price, $start, $end, $duration);
        $this->assertNotFalse($warm, 'The dynamic pricing discount was silently dropped on a warm cache read.');
        $this->assertSame('80.00', $warm->apply(clone $price)->format());
    }

    public function test_the_data_import_object_survives_a_warm_serializing_cache_read(): void
    {
        // ProcessDataImport reads this object back from cache in a later request.
        Cache::put('resrv_cache_data_import_probe', new DataImport('/tmp/resrv-import.csv', ',', 'pages', 'title'), 60);

        $retrieved = Cache::get('resrv_cache_data_import_probe');

        $this->assertInstanceOf(DataImport::class, $retrieved);
        $this->assertSame('/tmp/resrv-import.csv', $retrieved->getPath());
    }
}

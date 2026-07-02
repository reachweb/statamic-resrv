<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Jobs\ProcessDataImport;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityChangeImportTest extends TestCase
{
    use RefreshDatabase;

    public $item;

    public $rate;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.enable_activity_log', true);

        $this->item = $this->makeStatamicItem();
        $this->rate = Rate::factory()->create(['collection' => 'pages']);
    }

    private function cacheImport(array $rows): void
    {
        $fakeImport = new class($this->item->id(), $rows)
        {
            public function __construct(private $entryId, private $rows) {}

            public function prepare()
            {
                return collect([$this->entryId => collect($this->rows)]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);
    }

    public function test_importing_over_existing_rows_logs_only_the_diffs_in_one_batch()
    {
        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->addDay()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->addDays(2)->isoFormat('YYYY-MM-DD')],
            )
            ->create([
                'statamic_id' => $this->item->id(),
                'rate_id' => $this->rate->id,
                'available' => 2,
                'price' => 100,
            ]);

        $this->cacheImport([
            [
                'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
                'date_end' => today()->addDays(3)->isoFormat('YYYY-MM-DD'),
                'price' => 100,
                'available' => 5,
                'rate_id' => $this->rate->id,
            ],
        ]);

        (new ProcessDataImport('resrv-data-import', ['id' => '1', 'name' => 'Admin']))->handle();

        // Two existing dates change available (price unchanged, skipped); the new third date
        // logs a create row per field.
        $this->assertDatabaseCount('resrv_availability_changes', 4);
        $this->assertCount(1, AvailabilityChange::pluck('batch')->unique());

        $this->assertDatabaseHas('resrv_availability_changes', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 5,
            'reason' => 'import',
            'actor_id' => '1',
            'actor_name' => 'Admin',
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'date' => today()->addDays(3)->isoFormat('YYYY-MM-DD'),
            'action' => 'create',
            'field' => 'price',
            'old_value' => null,
            'new_value' => 100,
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'date' => today()->addDays(3)->isoFormat('YYYY-MM-DD'),
            'action' => 'create',
            'field' => 'available',
            'old_value' => null,
            'new_value' => 5,
        ]);
    }

    public function test_a_shared_rate_import_logs_the_base_pool_and_price_overrides()
    {
        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $this->rate->id,
            'pricing_type' => 'independent',
        ]);

        Availability::factory()->create([
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'price' => 100,
        ]);

        $this->cacheImport([
            [
                'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
                'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
                'price' => 120,
                'available' => 6,
                'rate_id' => $sharedRate->id,
            ],
        ]);

        (new ProcessDataImport('resrv-data-import', ['id' => '1', 'name' => 'Admin']))->handle();

        // The base pool logs the available change; the price override logs against the shared rate.
        $this->assertDatabaseCount('resrv_availability_changes', 2);

        $this->assertDatabaseHas('resrv_availability_changes', [
            'rate_id' => $this->rate->id,
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 6,
            'reason' => 'import',
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'rate_id' => $sharedRate->id,
            'action' => 'create',
            'field' => 'price',
            'old_value' => null,
            'new_value' => 120,
        ]);
    }

    public function test_nothing_is_logged_when_the_toggle_is_off()
    {
        Config::set('resrv-config.enable_activity_log', false);

        $this->cacheImport([
            [
                'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
                'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
                'price' => 100,
                'available' => 5,
                'rate_id' => $this->rate->id,
            ],
        ]);

        (new ProcessDataImport)->handle();

        $this->assertDatabaseCount('resrv_availability_changes', 0);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 5]);
    }
}

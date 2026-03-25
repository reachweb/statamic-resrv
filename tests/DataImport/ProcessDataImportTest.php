<?php

namespace Reach\StatamicResrv\Tests\DataImport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Jobs\ProcessDataImport;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class ProcessDataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_default_rate_when_rate_id_omitted_and_no_rates_exist()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow)
        {
            private $entryId;

            private $date;

            public function __construct($entryId, $date)
            {
                $this->entryId = $entryId;
                $this->date = $date;
            }

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 100,
                            'available' => 1,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        $this->assertDatabaseCount('resrv_rates', 1);
        $this->assertDatabaseHas('resrv_rates', [
            'collection' => 'pages',
            'slug' => 'default',
            'apply_to_all' => true,
        ]);

        $rate = Rate::first();
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $rate->id,
            'price' => 100,
            'available' => 1,
        ]);
    }

    public function test_import_uses_existing_rate_when_rate_id_omitted()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow)
        {
            private $entryId;

            private $date;

            public function __construct($entryId, $date)
            {
                $this->entryId = $entryId;
                $this->date = $date;
            }

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 200,
                            'available' => 3,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        // Should not create a new rate
        $this->assertDatabaseCount('resrv_rates', 1);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $rate->id,
            'price' => 200,
            'available' => 3,
        ]);
    }

    public function test_import_skips_row_when_multi_rate_entry_and_no_rate_id()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

        Rate::factory()->create(['collection' => 'pages', 'slug' => 'rate-a']);
        Rate::factory()->create(['collection' => 'pages', 'slug' => 'rate-b']);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow)
        {
            public function __construct(private $entryId, private $date) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 100,
                            'available' => 1,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);
        Log::shouldReceive('warning')->once();

        (new ProcessDataImport)->handle();

        $this->assertDatabaseCount('resrv_availabilities', 0);
    }

    public function test_import_works_for_multi_rate_entry_with_explicit_rate_id()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

        $rateA = Rate::factory()->create(['collection' => 'pages', 'slug' => 'rate-a']);
        Rate::factory()->create(['collection' => 'pages', 'slug' => 'rate-b']);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow, $rateA->id)
        {
            public function __construct(private $entryId, private $date, private $rateId) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 150,
                            'available' => 2,
                            'rate_id' => $this->rateId,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $rateA->id,
            'price' => 150,
            'available' => 2,
        ]);
    }

    public function test_import_resolves_shared_rate_to_base_rate_id()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow, $sharedRate->id)
        {
            public function __construct(private $entryId, private $date, private $rateId) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 100,
                            'available' => 2,
                            'rate_id' => $this->rateId,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        // Should be stored under the base rate, not the shared rate
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $baseRate->id,
            'price' => 100,
        ]);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'rate_id' => $sharedRate->id,
        ]);
    }

    public function test_import_leaves_independent_rate_id_unchanged()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'independent',
        ]);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow, $rate->id)
        {
            public function __construct(private $entryId, private $date, private $rateId) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 100,
                            'available' => 2,
                            'rate_id' => $this->rateId,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $rate->id,
            'price' => 100,
        ]);
    }
}

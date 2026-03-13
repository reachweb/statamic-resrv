<?php

namespace Reach\StatamicResrv\Tests\DataImport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $fakeImport = new class($entryId, $tomorrow) {
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

        $fakeImport = new class($entryId, $tomorrow) {
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
}

<?php

namespace Reach\StatamicResrv\Tests\DataImport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Jobs\ProcessDataImport;
use Reach\StatamicResrv\Models\Availability;
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

    public function test_import_skips_row_with_non_numeric_price()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();

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
                            'price' => 'not-a-number',
                            'available' => 1,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);
        Log::shouldReceive('warning')->once();

        (new ProcessDataImport)->handle();

        // Invalid cell is skipped before any write (and before a default rate is created).
        $this->assertDatabaseCount('resrv_availabilities', 0);
        $this->assertDatabaseCount('resrv_rates', 0);
    }

    public function test_import_skips_row_with_negative_availability()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();
        $rate = Rate::factory()->create(['collection' => 'pages']);

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
                            'available' => -5,
                            'rate_id' => $this->rateId,
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

    public function test_import_skips_row_with_fractional_availability()
    {
        // available is an integer inventory count, so 1.9 must be skipped rather than silently
        // truncated to 1 by the (int) cast.
        $this->assertFractionalAvailabilityIsSkipped(1.9);
    }

    public function test_import_skips_row_with_negative_fractional_availability()
    {
        // -0.5 passes is_numeric and (int) truncates it to 0 (>= 0), so without an integer check it
        // would be imported as available = 0 instead of being skipped.
        $this->assertFractionalAvailabilityIsSkipped(-0.5);
    }

    protected function assertFractionalAvailabilityIsSkipped($available): void
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $tomorrow = today()->addDay()->isoFormat('YYYY-MM-DD');

        $fakeImport = new class($entryId, $tomorrow, $rate->id, $available)
        {
            public function __construct(private $entryId, private $date, private $rateId, private $available) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->date,
                            'date_end' => $this->date,
                            'price' => 100,
                            'available' => $this->available,
                            'rate_id' => $this->rateId,
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

    public function test_import_skips_row_with_blank_date_range()
    {
        // A blank header date silently parses to "now" via Carbon, which would write an unintended
        // availability row for today instead of being skipped.
        $this->assertInvalidDateRangeIsSkipped('', '');
    }

    public function test_import_skips_row_with_unparseable_date_range()
    {
        // A garbage date would otherwise make CarbonPeriod::create() throw and abort the whole import;
        // it must be skipped + logged per row instead.
        $this->assertInvalidDateRangeIsSkipped('not-a-date', 'also-not-a-date');
    }

    public function test_import_skips_row_with_reversed_date_range()
    {
        // date_start after date_end yields an empty CarbonPeriod that writes nothing without a trace.
        $start = today()->addDays(5)->isoFormat('YYYY-MM-DD');
        $end = today()->addDay()->isoFormat('YYYY-MM-DD');
        $this->assertInvalidDateRangeIsSkipped($start, $end);
    }

    public function test_import_skips_row_with_overflow_date()
    {
        // Carbon::parse() would normalize 2024-02-30 to 2024-03-01 and write availability for the
        // wrong date. Strict YYYY-MM-DD parsing must reject it instead.
        $this->assertInvalidDateRangeIsSkipped('2024-02-30', '2024-03-15');
    }

    public function test_import_skips_row_with_relative_date_string()
    {
        // Relative strings like "next monday" are valid to Carbon::parse() but are not real header
        // dates — strict YYYY-MM-DD parsing must reject them.
        $this->assertInvalidDateRangeIsSkipped('next monday', 'next friday');
    }

    public function test_import_skips_row_with_non_iso_date_separator()
    {
        // Headers are expected to use YYYY-MM-DD; a slash-separated date is rejected rather than
        // silently coerced, so the row is skipped + logged instead of corrupting availability.
        $this->assertInvalidDateRangeIsSkipped('2024/01/15', '2024/01/20');
    }

    protected function assertInvalidDateRangeIsSkipped($dateStart, $dateEnd): void
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $fakeImport = new class($entryId, $dateStart, $dateEnd, $rate->id)
        {
            public function __construct(private $entryId, private $dateStart, private $dateEnd, private $rateId) {}

            public function prepare()
            {
                return collect([
                    $this->entryId => collect([
                        [
                            'date_start' => $this->dateStart,
                            'date_end' => $this->dateEnd,
                            'price' => 100,
                            'available' => 2,
                            'rate_id' => $this->rateId,
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

    public function test_import_coerces_numeric_string_values()
    {
        $item = $this->makeStatamicItem();
        $entryId = $item->id();
        $rate = Rate::factory()->create(['collection' => 'pages']);

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
                            'price' => '99.50',
                            'available' => '3',
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
            'price' => 99.5,
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

    public function test_import_resolves_shared_rate_to_base_rate_and_updates_availability_only()
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

        // Pre-seed a base rate row with the correct base price
        Availability::create([
            'statamic_id' => $entryId,
            'date' => $tomorrow,
            'price' => 200,
            'available' => 1,
            'rate_id' => $baseRate->id,
        ]);

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
                            'available' => 5,
                            'rate_id' => $this->rateId,
                        ],
                    ]),
                ]);
            }
        };

        Cache::put('resrv-data-import', $fakeImport);

        (new ProcessDataImport)->handle();

        // Availability updated on base rate, but price preserved (not overwritten by shared rate price)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entryId,
            'rate_id' => $baseRate->id,
            'price' => 200,
            'available' => 5,
        ]);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'rate_id' => $sharedRate->id,
        ]);
    }

    public function test_import_shared_rate_skips_dates_without_existing_base_rate_data()
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

        // No pre-existing base rate data — shared rate import should not create rows
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

        // No rows should be created — shared rate can't seed base rate prices
        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $entryId,
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

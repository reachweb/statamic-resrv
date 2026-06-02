<?php

namespace Reach\StatamicResrv\Tests\DataImport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Helpers\DataImport;
use Reach\StatamicResrv\Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function csvPath(string $contents): string
    {
        $path = sys_get_temp_dir().'/resrv-data-import-test-'.uniqid().'.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    // Multiple CSV rows for the same entry (e.g. one row per rate) must all be kept.
    // The old mapWithKeys() collapsed them to the last row, silently losing the rest.
    public function test_prepare_aggregates_multiple_rows_for_the_same_entry()
    {
        $entryA = $this->makeStatamicItem()->id();
        $entryB = $this->makeStatamicItem()->id();

        $path = $this->csvPath(
            "id,price:2026-01-01|2026-01-10,availability:2026-01-01|2026-01-10,rate_id\n".
            "{$entryA},100,5,10\n".
            "{$entryA},150,3,20\n".
            "{$entryB},200,1,30\n"
        );

        $prepared = (new DataImport($path, ',', 'pages', 'id'))->prepare();

        // Both rows for entry A survive (old behavior kept only the last, rate 20).
        $this->assertCount(2, $prepared->get($entryA));
        $this->assertEqualsCanonicalizing(['10', '20'], $prepared->get($entryA)->pluck('rate_id')->all());
        $this->assertEqualsCanonicalizing(['100', '150'], $prepared->get($entryA)->pluck('price')->all());

        // A different entry stays separate.
        $this->assertCount(1, $prepared->get($entryB));
        $this->assertEquals('30', $prepared->get($entryB)->first()['rate_id']);
    }

    // A price header drives the date range, so a header literally named "price" (no date range)
    // must be rejected at the confirm step rather than silently importing every row as a no-op.
    public function test_check_for_errors_rejects_a_price_header_without_a_date_range()
    {
        $path = $this->csvPath(
            "id,price,availability,rate_id\n".
            "entry-1,100,5,10\n"
        );

        $errors = (new DataImport($path, ',', 'pages', 'id'))->checkForErrors();

        $this->assertTrue($errors->isNotEmpty());
    }

    public function test_check_for_errors_passes_for_a_well_formed_price_header()
    {
        $path = $this->csvPath(
            "id,price:2026-01-01|2026-01-10,availability:2026-01-01|2026-01-10,rate_id\n".
            "entry-1,100,5,10\n"
        );

        $errors = (new DataImport($path, ',', 'pages', 'id'))->checkForErrors();

        $this->assertTrue($errors->isEmpty());
    }
}

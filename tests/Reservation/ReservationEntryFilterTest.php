<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Filters\ReservationEntry;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationEntryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_items_builds_options_from_distinct_reservation_entries()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory()->count(2)->create(['item_id' => $item->id()]);

        $options = (new ReservationEntry)->fieldItems()['entry']['options'];

        $this->assertSame([$item->id() => $item->title], $options->all());
    }

    // Statamic instantiates the filter afresh for every filtered data fetch just to call apply();
    // the option list must be built lazily, not in the constructor.
    public function test_constructing_and_applying_the_filter_runs_no_option_queries()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory()->create(['item_id' => $item->id()]);

        DB::enableQueryLog();
        $query = Reservation::query();
        (new ReservationEntry)->apply($query, ['entry' => [$item->id()]]);
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
        $this->assertEquals([$item->id()], $query->pluck('item_id')->all());
    }

    // The values come straight from the request's decoded `filters` payload, so `entry` can
    // arrive as null or be absent entirely — that must no-op, not TypeError on count(null).
    public function test_apply_ignores_null_or_missing_entry_values()
    {
        Reservation::factory()->count(2)->create(['item_id' => 'whatever']);

        $nullQuery = Reservation::query();
        (new ReservationEntry)->apply($nullQuery, ['entry' => null]);

        $missingQuery = Reservation::query();
        (new ReservationEntry)->apply($missingQuery, []);

        $this->assertSame(2, $nullQuery->count());
        $this->assertSame(2, $missingQuery->count());
    }
}

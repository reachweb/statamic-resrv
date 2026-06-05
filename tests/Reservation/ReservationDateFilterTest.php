<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Filters\ReservationMadeDate;
use Reach\StatamicResrv\Filters\ReservationStartingDate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationDateFilterTest extends TestCase
{
    use RefreshDatabase;

    // 'After' must use date semantics like the 'between' branch: a reservation made at 09:00 on
    // the picked day is not "after" that day. The previous raw `where > midnight` matched it.
    public function test_made_date_filter_after_excludes_rows_from_the_picked_day()
    {
        Reservation::factory()->create(['created_at' => '2024-01-15 09:00:00']);
        $dayAfter = Reservation::factory()->create(['created_at' => '2024-01-16 09:00:00']);

        $query = Reservation::query();
        (new ReservationMadeDate)->apply($query, ['operator' => '>', 'value' => ['date' => '2024-01-15']]);

        $this->assertEquals([$dayAfter->id], $query->pluck('id')->all());
    }

    public function test_made_date_filter_before_excludes_rows_from_the_picked_day()
    {
        $dayBefore = Reservation::factory()->create(['created_at' => '2024-01-14 23:00:00']);
        Reservation::factory()->create(['created_at' => '2024-01-15 09:00:00']);

        $query = Reservation::query();
        (new ReservationMadeDate)->apply($query, ['operator' => '<', 'value' => ['date' => '2024-01-15']]);

        $this->assertEquals([$dayBefore->id], $query->pluck('id')->all());
    }

    // date_start is a datetime column too (time-enabled bookings carry real times), so the same
    // day-boundary semantics must hold there.
    public function test_starting_date_filter_after_excludes_time_enabled_rows_from_the_picked_day()
    {
        Reservation::factory()->create(['date_start' => '2024-01-15 14:00:00']);
        $dayAfter = Reservation::factory()->create(['date_start' => '2024-01-16 14:00:00']);

        $query = Reservation::query();
        (new ReservationStartingDate)->apply($query, ['operator' => '>', 'value' => ['date' => '2024-01-15']]);

        $this->assertEquals([$dayAfter->id], $query->pluck('id')->all());
    }
}

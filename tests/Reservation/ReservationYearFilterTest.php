<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Filters\ReservationStartingDateYear;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationYearFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_items_lists_distinct_years_newest_first()
    {
        Reservation::factory()->create(['date_start' => '2023-05-10 10:00:00']);
        Reservation::factory()->create(['date_start' => '2023-08-12 10:00:00']);
        Reservation::factory()->create(['date_start' => '2025-01-02 10:00:00']);

        $options = (new ReservationStartingDateYear)->fieldItems()['date']['options'];

        $this->assertSame([2025 => '2025', 2023 => '2023'], $options);
    }

    public function test_apply_filters_by_year()
    {
        $in2023 = Reservation::factory()->create(['date_start' => '2023-05-10 10:00:00']);
        Reservation::factory()->create(['date_start' => '2025-01-02 10:00:00']);

        $query = Reservation::query();
        (new ReservationStartingDateYear)->apply($query, ['date' => '2023']);

        $this->assertEquals([$in2023->id], $query->pluck('id')->all());
    }
}

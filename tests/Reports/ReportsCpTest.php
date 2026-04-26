<?php

namespace Reach\StatamicResrv\Tests\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReportsCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_show_reports_page()
    {
        $response = $this->get(cp_route('resrv.reports.index'));
        $response->assertStatus(200)->assertSee('Reports');
    }

    public function test_can_get_report_data()
    {
        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(4)->create();

        $reservation2 = Reservation::factory([
            'item_id' => $item2->id(),
            'status' => 'confirmed',
        ])->count(2)->create();

        $response = $this->get(cp_route('resrv.report.index').'?start='.now()->toDateString().'&end='.now()->addWeek()->toDateString());
        $response->assertStatus(200)
            ->assertJson([
                'total_confirmed_reservations' => 6,
                'total_revenue' => '1200.00',
                'avg_revenue' => '200.00',
            ])
            ->assertSee('Test Statamic Item');
    }

    public function test_can_get_report_data_filtered_by_created_at()
    {
        $this->travelTo(today()->setHour(12));

        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->subYear()->toIso8601String(),
            'date_end' => today()->subYear()->addDays(2)->toIso8601String(),
        ])->count(3)->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.report.index')."?start={$start}&end={$end}&date_field=created_at")
            ->assertStatus(200)
            ->assertJson(['total_confirmed_reservations' => 3]);

        $this->get(cp_route('resrv.report.index')."?start={$start}&end={$end}&date_field=date_start")
            ->assertStatus(200)
            ->assertJson(['total_confirmed_reservations' => 0]);
    }

    public function test_validates_date_field()
    {
        $this->travelTo(today()->setHour(12));
        $this->withExceptionHandling();

        $this->getJson(cp_route('resrv.report.index').'?start='.today()->toDateString().'&end='.today()->addWeek()->toDateString().'&date_field=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_field']);
    }
}

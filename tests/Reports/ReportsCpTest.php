<?php

namespace Reach\StatamicResrv\Tests\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Report;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price;
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

    // Revenue is accumulated in integer minor units and returned as a Price, not summed as floats
    // (which CLAUDE.md forbids). Reverting to Collection::sum() on formatted strings would return a
    // float and fail the instanceof assertion.
    public function test_sum_and_avg_confirmed_reservations_return_exact_money()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'price' => '10.01'])
            ->count(3)->create();

        $report = new Report(now()->toDateString(), now()->addWeek()->toDateString());

        $this->assertInstanceOf(Price::class, $report->sumConfirmedReservations());
        $this->assertSame('30.03', $report->sumConfirmedReservations()->format());
        $this->assertSame('10.01', $report->avgConfirmedReservations()->format());

        // Per-item revenue aggregates through the same Price path; the payload stays numeric
        // (floats) on purpose — ReportsItemsTable.vue only number-sorts `typeof === 'number'`
        // values, so switching to formatted strings would silently break the revenue sort.
        $top = $report->topSellerItems();
        $this->assertSame(30.03, $top[0]['total_revenue']);
        $this->assertSame(10.01, $top[0]['avg_revenue']);
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

    // occurrences (count), revenue, and percentage must all use the same status set
    // (confirmed + partner) — not count confirmed-only while summing over both.
    public function test_top_seller_items_counts_confirmed_and_partner_consistently()
    {
        $itemA = $this->makeStatamicItem();
        $itemB = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $itemA->id(), 'status' => 'confirmed'])->count(3)->create();
        Reservation::factory(['item_id' => $itemA->id(), 'status' => 'partner'])->count(1)->create();
        Reservation::factory(['item_id' => $itemB->id(), 'status' => 'confirmed'])->count(2)->create();

        $top = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->topSellerItems();

        // Ordered by count desc: A (3 confirmed + 1 partner = 4), then B (2). 6 total.
        // Each row carries the unique item_id it was grouped by (used as the Vue row key).
        $this->assertEquals($itemA->id(), $top[0]['id']);
        $this->assertEquals($itemB->id(), $top[1]['id']);
        $this->assertEquals(4, $top[0]['reservations']);
        $this->assertSame(800.0, $top[0]['total_revenue']);
        $this->assertSame(200.0, $top[0]['avg_revenue']);
        $this->assertEquals(0.67, $top[0]['percentage']);

        $this->assertEquals(2, $top[1]['reservations']);
        $this->assertSame(400.0, $top[1]['total_revenue']);
        $this->assertSame(200.0, $top[1]['avg_revenue']);
        $this->assertEquals(0.33, $top[1]['percentage']);
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

<?php

namespace Reach\StatamicResrv\Tests\Reports;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ReportsCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
        $location = Location::factory()->create();

        $reservation = Reservation::factory([
            'customer' => ['email' => 'test@test.com'],
            'item_id' => $item->id(),
            'location_start' => $location->id,
            'location_end' => $location->id,
            'status' => 'confirmed',
        ])->count(4)->create();
        
        $reservation2 = Reservation::factory([
            'customer' => ['email' => 'test@test.com'],
            'item_id' => $item2->id(),
            'location_start' => $location->id,
            'location_end' => $location->id,
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

}
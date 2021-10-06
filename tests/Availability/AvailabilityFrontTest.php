<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Availability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class AvailabilityFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();        
    }

    // TODO: Break up this test a little bit
    public function test_availability_can_get_available_for_date_range()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(3, 'day')->toISOString(),
            'price' => 50,
            'available' => 2
        ];
        
        $payload2 = [
            'statamic_id' => $item2->id(),
            'date_start' => today()->add(2, 'day')->toISOString(),
            'date_end' => today()->add(5, 'day')->toISOString(),
            'price' => 80,
            'available' => 1
        ];
        $payload3 = [
            'statamic_id' => $item3->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(7, 'day')->toISOString(),
            'price' => 70,
            'available' => 5
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response = $this->post(cp_route('resrv.availability.update'), $payload2);
        $response = $this->post(cp_route('resrv.availability.update'), $payload3);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
        ];
        // We should see item 1, 3 but not item 2
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('150')->assertDontSee($item2->id())->assertSee($item3->id())->assertSee('210');

        $searchEmptyPayload = [
            'date_start' => today()->setHour(12)->add(15, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(20, 'day')->toISOString(),
        ];
        // Even when nothing is available we are getting a response
        $response = $this->post(route('resrv.availability.index'), $searchEmptyPayload);
        $response->assertStatus(200);

        // Add 2 hours to the end date and make sure that it charges an extra day (or not)
        $searchExtraDayPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->add(2, 'hours')->toISOString(),
        ];
        
        Config::set('resrv-config.calculate_days_using_time', false);
        $response = $this->post(route('resrv.availability.index'), $searchExtraDayPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('150');
        
        Config::set('resrv-config.calculate_days_using_time', true);
        $response = $this->post(route('resrv.availability.index'), $searchExtraDayPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('200');    
                
    } 
    
    public function test_availability_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(3, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
        ];
        // Check that it works
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('75.69');       
                
    }

    public function test_availability_when_not_set()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->toISOString(),
            'price' => 50,
            'available' => 2
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(2, 'day')->toISOString(),
            'date_end' => today()->add(4, 'day')->toISOString(),
            'price' => 50,
            'available' => 2
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertDontSee($item->id());

    }

    public function test_availability_honor_min_days_setting()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->toISOString(),
            'date_end' => today()->add(5, 'day')->toISOString(),
            'price' => 150,
            'available' => 2
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        Config::set('resrv-config.minimum_reservation_period_in_days', 3);
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(412)->assertDontSee($item->id());    
    }
    
    public function test_availability_honor_max_days_setting()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->toISOString(),
            'date_end' => today()->add(5, 'day')->toISOString(),
            'price' => 150,
            'available' => 2
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        Config::set('resrv-config.maximum_reservation_period_in_days', 3);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(5, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(412)->assertDontSee($item->id());    
    }

    public function test_that_it_respects_stop_sales()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem([
            'title' => 'Stop sales now!',
            'resrv_availability' => 'disabled'
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->toISOString(),
            'date_end' => today()->add(5, 'day')->toISOString(),
            'price' => 150,
            'available' => 2
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(5, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertDontSee($item->id());

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('{"message":{"status":false}}', false);

    }

    public function test_availability_can_get_available_for_date_range_one_id_only()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(3, 'day')->toISOString(),
            'price' => 50,
            'available' => 2
        ];
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'hour')->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('150')->assertSee('message":{"status":1}}', false);        
        
    }

    public function test_availability_can_get_unavailable_for_date_range_one_id_only()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(3, 'day')->toISOString(),
            'price' => 50,
            'available' => 0
        ];
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'hour')->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('{"message":{"status":false}}', false);        
        
    }

    public function test_availability_does_not_allow_bookings_closer_than_minimum_allowed()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(3, 'day')->toISOString(),
            'price' => 50,
            'available' => 2
        ];
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        Config::set('resrv-config.minimum_days_before', 2);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(412)->assertDontSee($item->id());        
        
    }

}

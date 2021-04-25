<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Availability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class AvailabilityFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_availability_can_get_available_for_date_range()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2
        ];
        
        $payload2 = [
            'statamic_id' => $item2->id(),
            'date_start' => today()->add(2, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 200,
            'available' => 1
        ];
        $payload3 = [
            'statamic_id' => $item3->id(),
            'date_start' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 100,
            'available' => 5
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response = $this->post(cp_route('resrv.availability.update'), $payload2);
        $response = $this->post(cp_route('resrv.availability.update'), $payload3);
        $response->assertStatus(200);

        $searchPayload = [
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
        ];       

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertDontSee($item2->id());
        
    }
    
    
    
}

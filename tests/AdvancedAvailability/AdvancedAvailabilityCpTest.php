<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class AdvancedAvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_advanced_availability_can_index_for_a_statamic_item()
    {
        $item = $this->makeStatamicItem();
        
        AdvancedAvailability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->get(cp_route('resrv.advancedavailability.index', [$item->id(), 'something']));
        $response->assertStatus(200)->assertSee($item->id());
        
    }
    

    public function test_advanced_availability_can_add_for_date_range()
    {
        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => 'something',
            'price' => 150,
            'available' => 2
        ];
        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'property' => 'something'
        ]);
    }

    public function test_availability_can_add_for_single_day()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id()
        ]);
    }

    // public function test_availability_can_stop_sales()
    // {
    //     $item = $this->makeStatamicItem();
    //     $payload = [
    //         'statamic_id' => $item->id(),
    //         'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
    //         'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
    //         'price' => 150,
    //         'available' => 0
    //     ];
    //     $response = $this->post(cp_route('resrv.availability.update'), $payload);
    //     $response->assertStatus(200);

    //     $this->assertDatabaseHas('resrv_availabilities', [
    //         'statamic_id' => $item->id()
    //     ]);
    // }
    
    // public function test_availability_can_update_for_date_range()
    // {
    //     $item = $this->makeStatamicItem();
    //     $payload = [
    //         'statamic_id' => $item->id(),
    //         'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
    //         'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
    //         'price' => 150,
    //         'available' => 6
    //     ];
    //     $response = $this->post(cp_route('resrv.availability.update'), $payload);
    //     $response->assertStatus(200);

    //     $this->assertDatabaseHas('resrv_availabilities', [
    //         'price' => 150
    //     ]);

    //     $newPayload = [
    //         'statamic_id' => $item->id(),
    //         'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
    //         'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
    //         'price' => 200,
    //         'available' => 2
    //     ];

    //     $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
    //     $response->assertStatus(200);

    //     $this->assertDatabaseHas('resrv_availabilities', [
    //         'price' => 200
    //     ]);
    // }
    
    
}

<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdvancedAvailabilityFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();        
    }

    public function test_that_we_can_query_advanced_availability()
    {
        $this->signInAdmin();

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

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something'
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id());

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something-else'
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertDontSee($item->id());

    }

    public function test_that_we_can_query_all_advanced_availability_items()
    {
        $this->signInAdmin();

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

        AdvancedAvailability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                [
                'statamic_id' => $item->id(),
                'property' => 'something-else',
                'price' => '100'
                ]
            );

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'any'
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('200');

    }

    public function test_advanced_availability_return_trip()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(8, 'day')->toISOString(),
            'price' => 72.5,
            'available' => 2,
            'advanced' => [['code' => 'something']]
        ];        

        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(6, 'day')->toISOString(),
            'advanced' => 'something',
            'round_trip' => true
        ];
        // We should see if that it's available and the total price
        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('145');

        // Test the show method as well
        $response = $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('145')->assertSee('message":{"status":1}}', false);
                
    } 
    
}

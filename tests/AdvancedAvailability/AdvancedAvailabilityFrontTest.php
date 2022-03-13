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
    
}

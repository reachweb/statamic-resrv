<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Tests\TestCase;

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
            'advanced' => 'something',
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('something');

        // Test show route
        $response = $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('something');

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something-else',
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertDontSee($item->id());
    }

    public function test_that_we_can_query_all_advanced_availability_items()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();

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
                    'statamic_id' => $item2->id(),
                    'property' => 'something-else',
                    'price' => '100',
                ]
            );

        AdvancedAvailability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                [
                    'statamic_id' => $item3->id(),
                    'property' => 'third-one',
                    'price' => '220',
                ]
            );

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'any',
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee($item2->id())->assertSee($item3->id())->assertSee('something');

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something|something-else',
        ];

        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee($item2->id())->assertDontSee($item3->id())->assertSee('something')->assertSee('something-else');
    }

    public function test_advanced_availability_multi_dates_availability()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(8, 'day')->toISOString(),
            'price' => 50,
            'available' => 2,
            'advanced' => [['code' => 'something']],
        ];

        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'dates' => [
                [
                    'date_start' => today()->setHour(12)->toISOString(),
                    'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(3, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(5, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(7, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
            ],
        ];

        // We should see if that it's available and the total price
        $response = $this->post(route('resrv.advancedavailability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('200')->assertSee('something');

        // Test the show method as well
        $response = $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('200')->assertSee('message":{"status":1}}', false);
    }
}

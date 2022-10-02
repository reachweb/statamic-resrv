<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Tests\TestCase;

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
            'advanced' => [['code' => 'something', 'label' => 'Something else']],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'property' => 'something',
        ]);
    }

    public function test_advanced_availability_can_add_mass_update_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something'], ['code' => 'something-else']],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'property' => 'something',
        ]);
        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'property' => 'something-else',
        ]);
    }

    public function test_advanced_availability_can_be_deleted_for_date_range()
    {
        $item = $this->makeStatamicItem();

        AdvancedAvailability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something', 'label' => 'Some label here']],
        ];

        $response = $this->delete(cp_route('resrv.advancedavailability.delete'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }
}

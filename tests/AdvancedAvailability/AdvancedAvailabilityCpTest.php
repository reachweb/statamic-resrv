<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class AdvancedAvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_advanced_availability_can_index_for_a_statamic_item()
    {
        $item = $this->makeStatamicItem();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create([
                'statamic_id' => $item->id(),
                'rate_id' => $rate->id,
            ]);

        $response = $this->get(cp_route('resrv.availability.index', [$item->id(), $rate->id]));
        $response->assertStatus(200)->assertSee($item->id());
    }

    public function test_advanced_availability_can_add_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate->id],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_advanced_availability_can_update_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'rate_ids' => [$rate->id],
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
            'available' => 6,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 200,
            'rate_ids' => [$rate->id],
            'available' => 2,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 200,
            'available' => 2,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_advanced_availability_can_add_mass_update_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $rate1 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);

        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test-else',
            'title' => 'Test Else',
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate1->id, $rate2->id],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate1->id,
        ])->assertDatabaseCount('resrv_availabilities', 10);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate2->id,
        ])->assertDatabaseCount('resrv_availabilities', 10);
    }

    public function test_advanced_availability_can_be_deleted_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item->id(),
                'rate_id' => $rate->id,
            ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate->id],
        ];

        $response = $this->delete(cp_route('resrv.availability.delete'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }
}

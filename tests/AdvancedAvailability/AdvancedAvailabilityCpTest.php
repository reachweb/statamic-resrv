<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AdvancedAvailabilityCpTest extends TestCase
{
    use CreatesEntries;

    public $item;

    public $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();

        $this->item = $this->makeStatamicItem();

        $this->rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test',
            'title' => 'Test',
        ]);
    }

    public function test_advanced_availability_can_index_for_a_statamic_item()
    {
        $this->createAvailabilityForEntry($this->item, 150, 2, $this->rate->id, 2);

        $response = $this->get(cp_route('resrv.availability.index', [$this->item->id(), $this->rate->id]));
        $response->assertStatus(200)->assertSee($this->item->id());
    }

    public function test_advanced_availability_can_add_for_date_range()
    {
        $payload = [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$this->rate->id],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
        ]);
    }

    public function test_advanced_availability_can_update_for_date_range()
    {
        $payload = [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'rate_ids' => [$this->rate->id],
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
            'available' => 6,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 200,
            'rate_ids' => [$this->rate->id],
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
        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'test-else',
            'title' => 'Test Else',
        ]);

        $payload = [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$this->rate->id, $rate2->id],
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 10);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $rate2->id,
        ])->assertDatabaseCount('resrv_availabilities', 10);
    }

    public function test_advanced_availability_can_be_deleted_for_date_range()
    {
        $this->createAvailabilityForEntry($this->item, 150, 2, $this->rate->id, dates: [
            today()->isoFormat('YYYY-MM-DD'),
            today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->item->id(),
        ]);

        $payload = [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$this->rate->id],
        ];

        $response = $this->delete(cp_route('resrv.availability.delete'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $this->item->id(),
        ]);
    }
}

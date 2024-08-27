<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_entry_saved_on_the_entries_table_on_save()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $item->save();

        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => $item->get('title'),
            'enabled' => 1,
            'collection' => $item->collection(),
            'handle' => $item->blueprint(),
        ]);
    }

    public function test_entry_does_not_get_saved_on_the_entries_table_if_no_resrv_availability_field()
    {
        $item = $this->makeStatamicWithoutResrvAvailabilityField([
            'title' => 'This is an entry',
        ]);

        $item->save();

        $this->assertDatabaseMissing('resrv_entries', [
            'item_id' => $item->id(),
            'title' => $item->get('title'),
        ]);
    }

    public function test_entry_disabled_when_resrv_availability_off()
    {
        $item = $this->makeStatamicItem();

        $item->save();

        $item->set('resrv_availability', 'disabled');

        $item->save();

        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => $item->get('title'),
            'enabled' => 0,
            'collection' => $item->collection(),
            'handle' => $item->blueprint(),
        ]);
    }

    public function test_entry_deletion_soft_deletes()
    {
        $item = $this->makeStatamicItem();

        $item->delete();

        $this->assertSoftDeleted('resrv_entries', [
            'item_id' => $item->id(),
        ]);
    }

    public function test_availability_can_index_for_a_statamic_item()
    {
        $item = $this->makeStatamicItem();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->get(cp_route('resrv.availability.index', $item->id()));
        $response->assertStatus(200)->assertSee($item->id());
    }

    public function test_availability_returns_empty_array_not_found()
    {
        $item = $this->makeStatamicItem();

        Availability::factory()
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->get(cp_route('resrv.availability.index', 'test'));
        $response->assertStatus(200)->assertSee('[]');
    }

    public function test_availability_can_add_for_date_range()
    {
        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
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
            'available' => 2,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }

    public function test_availability_can_stop_sales()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 0,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }

    public function test_availability_can_update_for_date_range()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 200,
            'available' => 2,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 200,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_can_update_without_price()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'price' => 150,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_cannot_update_if_price_missing()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(302)->assertInvalid(['available']);
    }

    public function test_availability_can_save_price_when_availability_missing()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 120,
            'available' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(302)->assertInvalid(['price']);
    }

    public function test_availability_cannot_save_price_without_availability()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 120,
            'available' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'price' => 120,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_can_delete_for_date_range()
    {
        $item = $this->makeStatamicItem();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
        ];

        $response = $this->delete(cp_route('resrv.availability.delete'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }
}

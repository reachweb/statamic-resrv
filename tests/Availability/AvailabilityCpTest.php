<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
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
            'collection' => $item->collection()->handle(),
            'handle' => $item->blueprint()->handle(),
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
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $item->set('resrv_availability', 'disabled');

        $item->save();

        $this->assertDatabaseHas('resrv_entries', [
            'item_id' => $item->id(),
            'title' => $item->get('title'),
            'enabled' => 0,
            'collection' => $item->collection()->handle(),
            'handle' => $item->blueprint()->handle(),
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

    public function test_entry_deletion_hard_deletes_availability_which_a_mirror_restore_does_not_bring_back()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);

        $item->delete();

        // The mirror is recoverable (soft-deleted) but availability is hard-deleted.
        $this->assertSoftDeleted('resrv_entries', ['item_id' => $item->id()]);
        $this->assertDatabaseMissing('resrv_availabilities', ['statamic_id' => $item->id()]);

        // Re-saving restores the mirror (single row, not a duplicate) but availability stays gone.
        $item->save();

        $this->assertDatabaseHas('resrv_entries', ['item_id' => $item->id(), 'deleted_at' => null]);
        $this->assertDatabaseMissing('resrv_availabilities', ['statamic_id' => $item->id()]);
    }

    public function test_availability_can_index_for_a_statamic_item()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

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

    public function test_availability_returns_empty_array_not_found()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->create([
                'statamic_id' => $item->id(),
                'rate_id' => $rate->id,
            ]);

        $response = $this->get(cp_route('resrv.availability.index', 'test'));
        $response->assertStatus(200)->assertSee('[]');
    }

    public function test_availability_can_add_for_date_range()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(5, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_availability_can_add_for_single_day()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_availability_can_stop_sales()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 0,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_availability_can_update_for_date_range()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 200,
            'available' => 2,
            'rate_ids' => [$rate->id],
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 200,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_can_update_without_price()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'price' => null,
            'rate_ids' => [$rate->id],
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'price' => 150,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_cannot_update_if_price_missing()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'price' => 150,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'price' => null,
            'rate_ids' => [$rate->id],
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(302)->assertInvalid(['available']);
    }

    public function test_availability_can_update_when_price_key_is_omitted_entirely()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        // Seed availability so the "available without price" path has existing prices to satisfy.
        $this->post(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ])->assertStatus(200);

        // A partial request that omits the price key entirely must not raise undefined-array-key
        // warnings in the controller/rule and must update availability normally.
        $response = $this->post(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'price' => 150,
            'rate_id' => $rate->id,
        ]);
    }

    public function test_availability_update_requires_price_or_available()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        // Neither price nor available provided — the request must be rejected rather than silently no-op.
        $response = $this->post(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(302)->assertInvalid(['price', 'available']);
    }

    public function test_availability_can_save_price_when_availability_missing()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(4, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 120,
            'available' => null,
            'rate_ids' => [$rate->id],
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(302)->assertInvalid(['price']);
    }

    public function test_availability_cannot_save_price_without_availability()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 120,
            'available' => null,
            'rate_ids' => [$rate->id],
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'price' => 120,
            'rate_id' => $rate->id,
        ])->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_availability_can_delete_for_date_range()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

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
            'rate_id' => $rate->id,
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
            'rate_id' => $rate->id,
        ]);
    }

    public function test_availability_update_without_rate_ids_uses_existing_default_rate()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
        ];

        $response = $this->postJson(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'price' => 150,
            'available' => 2,
        ]);
    }

    public function test_availability_update_without_rate_ids_creates_default_rate_when_none_exist()
    {
        $item = $this->makeStatamicItem();

        $this->assertDatabaseCount('resrv_rates', 0);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
        ];

        $response = $this->postJson(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseCount('resrv_rates', 1);
        $this->assertDatabaseHas('resrv_rates', [
            'collection' => 'pages',
            'slug' => 'default',
            'apply_to_all' => true,
        ]);

        $rate = Rate::first();
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'price' => 150,
            'available' => 2,
        ]);
    }

    public function test_availability_update_without_rate_ids_reuses_existing_default_rate_not_assigned_to_entry()
    {
        $item = $this->makeStatamicItem();

        // Create a default rate for same collection but NOT apply_to_all and NOT assigned to this entry
        $existingDefault = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'default',
            'title' => 'Default',
            'apply_to_all' => false,
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(3, 'day')->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 2,
        ];

        $response = $this->postJson(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        // Should reuse the existing default rate, not create a new one
        $this->assertDatabaseCount('resrv_rates', 1);

        // Should have attached pivot so forEntry() finds it
        $this->assertDatabaseHas('resrv_rate_entries', [
            'rate_id' => $existingDefault->id,
            'statamic_id' => $item->id(),
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $existingDefault->id,
            'price' => 150,
        ]);
    }

    public function test_availability_can_update_for_date_range_with_specific_days()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $startDate = today()->next(Carbon::SUNDAY);
        $endDate = $startDate->copy()->addDays(6);

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => $startDate->isoFormat('YYYY-MM-DD'),
            'date_end' => $endDate->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 6,
            'onlyDays' => [1, 3, 5], // Monday, Wednesday, Friday
            'rate_ids' => [$rate->id],
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        // Calculate the specific dates we expect
        $expectedMonday = $startDate->copy()->addDays(1)->isoFormat('YYYY-MM-DD');
        $expectedWednesday = $startDate->copy()->addDays(3)->isoFormat('YYYY-MM-DD');
        $expectedFriday = $startDate->copy()->addDays(5)->isoFormat('YYYY-MM-DD');
        $unexpectedTuesday = $startDate->copy()->addDays(2)->isoFormat('YYYY-MM-DD');

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => $expectedMonday,
            'price' => 150,
            'available' => 6,
            'rate_id' => $rate->id,
        ]);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => $expectedWednesday,
            'price' => 150,
            'available' => 6,
            'rate_id' => $rate->id,
        ]);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => $expectedFriday,
            'price' => 150,
            'available' => 6,
            'rate_id' => $rate->id,
        ]);

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => $unexpectedTuesday,
        ]);

        $this->assertDatabaseCount('resrv_availabilities', 3);
    }

    public function test_update_rejects_when_pending_reservation_overlaps()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 8,
            'pending' => [42],
        ]);

        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 10,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 8]);
    }

    public function test_update_succeeds_for_price_only_change_with_pending_reservation()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 8,
            'pending' => [42],
        ]);

        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'price' => 999,
            'available' => null,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 8]);
    }

    public function test_update_rejects_when_an_awaiting_payment_reservation_overlaps()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 8,
        ]);

        // An admin-created hold that decremented stock releases +quantity asynchronously (hold
        // lapse / CP cancel), so an absolute edit made while it is active would be corrupted.
        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'awaiting_payment',
            'affects_availability' => true,
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 10,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 8]);
    }

    public function test_update_allows_the_edit_when_a_view_only_awaiting_payment_reservation_overlaps()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 8,
        ]);

        // affects_availability=false never restores +quantity, so it cannot corrupt an absolute
        // edit and must not block it (manual holds can persist for days).
        Reservation::factory()->create([
            'id' => 43,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'awaiting_payment',
            'affects_availability' => false,
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'price' => 150,
            'available' => 10,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 10]);
    }

    public function test_delete_rejects_when_pending_reservation_overlaps()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'available' => 6,
            'pending' => [42],
        ]);

        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_update_succeeds_when_only_confirmed_reservations_overlap()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // Confirmed bookings keep their hold key in `pending` for their whole life —
        // a busy date must still allow absolute inventory edits.
        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'confirmed',
        ]);

        Reservation::factory()->create([
            'id' => 43,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
            'status' => 'partner',
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 0,
            'pending' => ['r42', 'r43'],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'price' => 150,
            'available' => 10,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);

        $availability->refresh();
        $this->assertEquals(10, $availability->available);
        // The hold keys must survive the edit — a later refund still needs them to restore stock.
        $this->assertEquals(['r42', 'r43'], $availability->pending);
    }

    public function test_update_rejects_when_pending_checkout_within_hold_window_overlaps()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // A live checkout: its asynchronous expiry would release +quantity on top of the edit.
        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 8,
            'pending' => ['r42'],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'price' => 150,
            'available' => 10,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 8]);
    }

    public function test_update_succeeds_when_only_confirmed_child_reservation_overlaps()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $parent = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
            'status' => 'confirmed',
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
        ]);

        $child = ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 3,
            'pending' => ['c'.$child->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'price' => 150,
            'available' => 7,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);

        $availability->refresh();
        $this->assertEquals(7, $availability->available);
        $this->assertEquals(['c'.$child->id], $availability->pending);
    }

    public function test_update_rejects_when_pending_child_reservation_overlaps()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // Parent dates far outside the edited range so only the child-via-parent path can block.
        $parent = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
            'status' => 'pending',
            'date_start' => today()->addDays(20)->toDateString(),
            'date_end' => today()->addDays(22)->toDateString(),
        ]);

        $child = ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 3,
            'pending' => ['c'.$child->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'price' => 150,
            'available' => 7,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 3]);
    }

    public function test_delete_rejects_when_only_confirmed_reservations_overlap()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // Deleting the rows would orphan the booking's hold key — a later refund's
        // removeFromPending would find nothing to restore. Deletion stays blocked.
        Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'confirmed',
        ]);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 4,
            'pending' => ['r42'],
        ]);

        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete availability while active reservations exist for this date range.']);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_refund_after_edit_restores_stock_on_top_of_edited_value()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $reservation = Reservation::factory()->create([
            'id' => 42,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'confirmed',
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 8,
            'pending' => ['r42'],
        ]);

        $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'price' => 150,
            'available' => 5,
            'rate_ids' => [$rate->id],
        ])->assertStatus(200);

        try {
            Event::dispatch(new ReservationRefunded($reservation));
        } catch (\Exception $e) {
            // Listeners registered after IncreaseAvailability (logging/emails) may throw for
            // these bare factory reservations; the availability restore has already happened.
        }

        // The refund restores its quantity on top of the admin's edited value and removes the hold key.
        $availability->refresh();
        $this->assertEquals(7, $availability->available);
        $this->assertEquals([], $availability->pending);
    }

    public function test_index_reports_stuck_holds_only_for_dead_or_abandoned_holders()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $dateA = today()->addDay()->isoFormat('YYYY-MM-DD');
        $dateB = today()->addDays(2)->isoFormat('YYYY-MM-DD');

        // r1 is missing from both tables (orphan), r2 is terminal, r3 is an abandoned
        // checkout past its hold window, r4 is a healthy confirmed booking.
        Reservation::factory()->expired()->create([
            'id' => 2,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $dateA,
            'date_end' => $dateB,
        ]);

        $stale = Reservation::factory()->create([
            'id' => 3,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $dateA,
            'date_end' => $dateB,
            'status' => 'pending',
        ]);
        $stale->created_at = now()->subMinutes(60);
        $stale->saveQuietly();

        Reservation::factory()->create([
            'id' => 4,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $dateA,
            'date_end' => today()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $dateA,
            'available' => 2,
            'pending' => ['r1', 'r2', 'r3', 'r4'],
        ]);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $dateB,
            'available' => 2,
            'pending' => ['r4'],
        ]);

        $response = $this->get(cp_route('resrv.availability.index', [$item->id(), $rate->id]));

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json("data.{$dateA}.stuck_holds"));
        // A date held only by a confirmed booking is the normal state — nothing stuck.
        $this->assertEquals(0, $response->json("data.{$dateB}.stuck_holds"));
    }

    public function test_clear_stuck_pending_reports_expirable_holds_separately_from_active()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        Reservation::factory()->create([
            'id' => 21,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
            'status' => 'confirmed',
        ]);

        $stale = Reservation::factory()->create([
            'id' => 22,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
            'status' => 'pending',
        ]);
        $stale->created_at = now()->subMinutes(60);
        $stale->saveQuietly();

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 2,
            'pending' => ['r21', 'r22'],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
        ]);

        // Both holders are alive, so nothing is cleared — but only the abandoned checkout
        // (22) is reported as force-expirable; the confirmed booking (21) is not.
        $response->assertStatus(200)->assertJson(['cleared' => 0]);
        $this->assertEqualsCanonicalizing([21, 22], $response->json('still_active'));
        $this->assertEquals([22], $response->json('expirable'));

        $availability->refresh();
        $this->assertEquals(2, $availability->available);
        $this->assertEquals(['r21', 'r22'], $availability->pending);
    }

    public function test_delete_succeeds_when_pending_reservation_is_terminal()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        // Reservation exists but is expired (terminal) — should not block.
        Reservation::factory()->expired()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
        ]);

        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_clear_stuck_pending_removes_terminal_holds_and_restores_quantity()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $reservation = Reservation::factory()->expired()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 4,
            'pending' => [$reservation->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['cleared' => 1, 'still_active' => []]);

        $availability->refresh();
        $this->assertEquals(6, $availability->available);
        $this->assertEquals([], $availability->pending);
    }

    public function test_clear_stuck_pending_leaves_active_holds_by_default()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 4,
            'pending' => [$reservation->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'cleared' => 0,
                'still_active' => [$reservation->id],
            ]);

        $availability->refresh();
        $this->assertEquals(4, $availability->available);
        $this->assertEquals([$reservation->id], $availability->pending);
    }

    public function test_clear_stuck_pending_force_mode_expires_stale_pending_reservation()
    {
        Config::set('resrv-config.minutes_to_hold', 10);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 3,
            'status' => 'pending',
        ]);

        // Past its hold window — a genuinely abandoned checkout, safe to expire.
        $reservation->created_at = now()->subMinutes(60);
        $reservation->saveQuietly();

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 2,
            'pending' => [$reservation->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
            'force' => true,
        ]);

        // Force expires the stale reservation: releases inventory and terminalises it (unblocking deletes).
        $response->assertStatus(200)
            ->assertJson(['cleared' => 1, 'still_active' => []]);

        $this->assertEquals('expired', $reservation->fresh()->status);

        $availability->refresh();
        $this->assertEquals(5, $availability->available);
        $this->assertEquals([], $availability->pending);
    }

    public function test_clear_stuck_pending_force_mode_protects_within_window_pending_reservation()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // Inside its hold window — may be mid-payment; even a forced clear must leave it untouched.
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 3,
            'status' => 'pending',
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 2,
            'pending' => [$reservation->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
            'force' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson(['cleared' => 0, 'still_active' => [$reservation->id]]);

        // Untouched: status, hold, and inventory all unchanged.
        $this->assertEquals('pending', $reservation->fresh()->status);

        $availability->refresh();
        $this->assertEquals(2, $availability->available);
        $this->assertEquals([$reservation->id], $availability->pending);
    }

    public function test_clear_stuck_pending_force_mode_leaves_confirmed_hold_blocking()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        // A confirmed booking is never auto-released.
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 3,
            'status' => 'confirmed',
        ]);

        $availability = Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 2,
            'pending' => [$reservation->id],
        ]);

        $response = $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
            'force' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson(['cleared' => 0, 'still_active' => [$reservation->id]]);

        $this->assertEquals('confirmed', $reservation->fresh()->status);

        $availability->refresh();
        $this->assertEquals(2, $availability->available);
        $this->assertEquals([$reservation->id], $availability->pending);
    }

    public function test_force_clearing_stuck_pending_then_deleting_succeeds()
    {
        Config::set('resrv-config.minutes_to_hold', 10);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        // A genuinely stuck checkout: abandoned past its hold window but never pruned.
        $reservation->created_at = now()->subMinutes(60);
        $reservation->saveQuietly();

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 4,
            'pending' => [$reservation->id],
        ]);

        // Force-clear the stuck hold...
        $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $item->id(),
            'date' => $date,
            'rate_id' => $rate->id,
            'force' => true,
        ])->assertStatus(200);

        $this->assertEquals('expired', $reservation->fresh()->status);

        // ...then the previously-422 delete succeeds.
        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_delete_auto_expires_stale_pending_reservation_then_succeeds()
    {
        Config::set('resrv-config.minutes_to_hold', 10);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);
        $date = today()->addDay()->isoFormat('YYYY-MM-DD');

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $date,
            'available' => 4,
            'pending' => ['r77'],
        ]);

        $reservation = Reservation::factory()->create([
            'id' => 77,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $date,
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        // Past its hold window but never pruned — the CP delete's lazy expiry clears it.
        $reservation->created_at = now()->subMinutes(60);
        $reservation->saveQuietly();

        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => $date,
            'date_end' => $date,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('expired', $reservation->fresh()->status);
        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
    }

    public function test_update_does_not_expire_the_admins_own_session_held_hold()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $heldDate = today()->addDays(10)->isoFormat('YYYY-MM-DD');
        $editDate = today()->addDay()->isoFormat('YYYY-MM-DD');

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $heldDate,
            'available' => 4,
            'pending' => ['r91'],
        ]);

        // A fresh in-progress checkout in another tab of the same session.
        $held = Reservation::factory()->create([
            'id' => 91,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $heldDate,
            'date_end' => today()->addDays(11)->toDateString(),
            'quantity' => 1,
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
        ]);
        session()->put('resrv_reservation', $held->id);

        $response = $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $item->id(),
            'date_start' => $editDate,
            'date_end' => $editDate,
            'price' => 150,
            'available' => 3,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);

        // The fresh hold and its reserved inventory must be left untouched.
        $this->assertEquals('pending', $held->fresh()->status);
        $this->assertEquals(4, Availability::where('statamic_id', $item->id())
            ->where('rate_id', $rate->id)
            ->whereDate('date', $heldDate)
            ->value('available'));
    }

    public function test_delete_does_not_expire_the_admins_own_session_held_hold()
    {
        Config::set('resrv-config.minutes_to_hold', 30);

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $heldDate = today()->addDays(10)->isoFormat('YYYY-MM-DD');
        $deleteDate = today()->addDay()->isoFormat('YYYY-MM-DD');

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $heldDate,
            'available' => 4,
            'pending' => ['r92'],
        ]);
        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $deleteDate,
            'available' => 4,
        ]);

        // A fresh in-progress checkout in another tab of the same session.
        $held = Reservation::factory()->create([
            'id' => 92,
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $heldDate,
            'date_end' => today()->addDays(11)->toDateString(),
            'quantity' => 1,
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
        ]);
        session()->put('resrv_reservation', $held->id);

        $response = $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $item->id(),
            'date_start' => $deleteDate,
            'date_end' => $deleteDate,
            'rate_ids' => [$rate->id],
        ]);

        $response->assertStatus(200);

        // Deleting an unrelated date must not abandon the fresh hold or release its inventory.
        $this->assertEquals('pending', $held->fresh()->status);
        $this->assertEquals(4, Availability::where('statamic_id', $item->id())
            ->where('rate_id', $rate->id)
            ->whereDate('date', $heldDate)
            ->value('available'));
    }
}

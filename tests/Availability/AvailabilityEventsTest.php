<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_availability_decreases_on_reservation_created()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id(), 'rate_id' => $rate->id]
            );

        $reservation = Reservation::factory()
            ->withRate($rate->id)
            ->create(
                ['item_id' => $item->id()]
            );

        Event::dispatch(new ReservationCreated($reservation));

        // Pending key is namespaced: 'r'<id> for a normal reservation.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 1,
        ], 'pending', '["r1"]');
    }

    public function test_availability_increases_on_reservation_expired()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->withPendingArray()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id(), 'rate_id' => $rate->id]
            );

        $reservation = Reservation::factory()
            ->withRate($rate->id)
            ->create(
                ['item_id' => $item->id()]
            );

        $this->dispatchEventAndCatchException(new ReservationExpired($reservation));

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 3,
        ], 'pending', '[2,3]');
    }

    public function test_availability_does_increase_multiple_times_on_reservation_expired()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->withPendingArray()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id(), 'rate_id' => $rate->id]
            );

        $reservation = Reservation::factory()
            ->withRate($rate->id)
            ->create(
                ['item_id' => $item->id()]
            );

        $reservation2 = Reservation::factory()
            ->withRate($rate->id)
            ->create(
                ['item_id' => $item->id()]
            );

        $this->dispatchEventAndCatchException(new ReservationExpired($reservation));
        $this->dispatchEventAndCatchException(new ReservationExpired($reservation));
        $this->dispatchEventAndCatchException(new ReservationExpired($reservation));

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 3,
        ], 'pending', '[2,3]');

        $this->dispatchEventAndCatchException(new ReservationExpired($reservation2));

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 4,
        ], 'pending', '[3]');
    }

    // Normal and child reservations have independent auto-increment sequences and can share the same integer id.
    // Both holds must be subtracted; namespaced pending keys ('r'/'c') prevent the second decrement being skipped.
    public function test_normal_and_child_reservation_with_colliding_ids_both_decrement_availability()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'available' => 5,
        ]);

        $normal = Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $item->id(),
            'quantity' => 1,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        $parent = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
        ]);
        $child = ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parent->id,
            'quantity' => 2,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        $this->assertSame($normal->id, $child->id, 'Test setup requires the ids to collide.');

        Event::dispatch(new ReservationCreated($normal));
        Event::dispatch(new ReservationCreated($parent));

        // 5 − 1 (normal 'r1') − 2 (child 'c1') = 2.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 2,
        ], 'pending', '["r1","c1"]');
    }

    // Releasing one colliding hold must restore only its stock and leave the other's pending entry.
    public function test_releasing_one_of_two_colliding_holds_restores_only_its_own_stock()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'available' => 5,
        ]);

        $normal = Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $item->id(),
            'quantity' => 1,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        $parent = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
        ]);
        ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parent->id,
            'quantity' => 2,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        Event::dispatch(new ReservationCreated($normal));
        Event::dispatch(new ReservationCreated($parent));

        $this->dispatchEventAndCatchException(new ReservationExpired($normal));

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 3,
        ], 'pending', '["c1"]');
    }

    // Expiring a parent restores each child's stock (incrementMultiple), leaving a normal hold untouched.
    public function test_expiring_a_parent_reservation_restores_its_children_stock()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'available' => 5,
        ]);

        $normal = Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $item->id(),
            'quantity' => 1,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        $parent = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
        ]);
        ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parent->id,
            'quantity' => 2,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        Event::dispatch(new ReservationCreated($normal));
        Event::dispatch(new ReservationCreated($parent));

        // 5 − 1 (normal 'r1') − 2 (child 'c1') = 2.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 2,
        ], 'pending', '["r1","c1"]');

        $this->dispatchEventAndCatchException(new ReservationExpired($parent));

        // Only the child's 2 are restored; the normal hold remains held.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 4,
        ], 'pending', '["r1"]');
    }

    public function dispatchEventAndCatchException($event)
    {
        try {
            Event::dispatch($event);
        } catch (\Exception $e) {
            // Handle the exception or log it
        }
    }
}

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

    // Test that availability decreases when the ReservationCreated event is dispatched
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

        // Pending entries are namespaced by reservation type: 'r'<id> for a normal reservation.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 1,
        ], 'pending', '["r1"]');
    }

    // Test that availability is restored when a reservation expires
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

    // Test that availability doesn't increase multiple times when the same reservation expires repeatedly (useful in race conditions)
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

    // A normal reservation and a child reservation can share the same integer id (they are
    // independent auto-increment sequences). When both target the same availability row, both
    // holds must be subtracted — before pending keys were namespaced the second decrement was
    // silently skipped, overbooking the row.
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

        // First normal reservation -> resrv_reservations.id = 1.
        $normal = Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $item->id(),
            'quantity' => 1,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(1, 'day')->toIso8601String(),
        ]);

        // Parent + first child -> resrv_child_reservations.id = 1, colliding with the normal id.
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

        // 5 - 1 (normal) - 2 (child) = 2; both pending entries are present and namespaced.
        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 2,
        ], 'pending', '["r1","c1"]');
    }

    // Releasing one of two colliding holds must restore only that holder's stock and leave the
    // other's pending entry in place.
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

        // Release the normal reservation only; the child's hold ('c1') must remain.
        $this->dispatchEventAndCatchException(new ReservationExpired($normal));

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 3,
        ], 'pending', '["c1"]');
    }

    // Helper method to dispatch an event and catch any exceptions
    public function dispatchEventAndCatchException($event)
    {
        try {
            Event::dispatch($event);
        } catch (\Exception $e) {
            // Handle the exception or log it
        }
    }
}

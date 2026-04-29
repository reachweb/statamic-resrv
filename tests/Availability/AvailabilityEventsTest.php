<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Models\Availability;
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

        $this->assertDatabaseHasJsonColumn('resrv_availabilities', [
            'available' => 1,
        ], 'pending', '[1]');
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

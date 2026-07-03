<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityChangeReservationTest extends TestCase
{
    use RefreshDatabase;

    public $item;

    public $rate;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.enable_activity_log', true);

        $this->item = $this->makeStatamicItem();
        $this->rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->addDay()->isoFormat('YYYY-MM-DD')],
            )
            ->create([
                'statamic_id' => $this->item->id(),
                'rate_id' => $this->rate->id,
                'available' => 3,
            ]);
    }

    private function dispatchEventAndCatchException(object $event): void
    {
        try {
            Event::dispatch($event);
        } catch (\Exception $e) {
            // Listeners registered after the availability ones (e.g. the refund emails) may
            // throw for these bare factory reservations; the availability writes and their
            // log rows have already happened by then.
        }
    }

    private function makeReservation(): Reservation
    {
        return Reservation::factory()->withRate($this->rate->id)->create([
            'item_id' => $this->item->id(),
            'quantity' => 1,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ]);
    }

    public function test_reservation_creation_logs_one_row_per_date_in_one_batch()
    {
        $reservation = $this->makeReservation();

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseCount('resrv_availability_changes', 2);
        $this->assertCount(1, AvailabilityChange::pluck('batch')->unique());

        foreach ([today(), today()->addDay()] as $date) {
            $this->assertDatabaseHas('resrv_availability_changes', [
                'statamic_id' => $this->item->id(),
                'rate_id' => $this->rate->id,
                'date' => $date->isoFormat('YYYY-MM-DD'),
                'action' => 'update',
                'field' => 'available',
                'old_value' => 3,
                'new_value' => 2,
                'reason' => 'reservation_created',
                'reservation_id' => $reservation->id,
                'actor_id' => null,
            ]);
        }
    }

    public function test_release_events_log_the_increment_with_their_own_reason()
    {
        $reservation = $this->makeReservation();
        Event::dispatch(new ReservationCreated($reservation));

        AvailabilityChange::query()->delete();

        $this->dispatchEventAndCatchException(new ReservationExpired($reservation));

        $this->assertDatabaseCount('resrv_availability_changes', 2);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'old_value' => 2,
            'new_value' => 3,
            'reason' => 'reservation_expired',
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_cancelled_and_refunded_events_map_to_their_reasons()
    {
        foreach ([
            [ReservationCancelled::class, 'reservation_cancelled'],
            [ReservationRefunded::class, 'reservation_refunded'],
        ] as [$eventClass, $reason]) {
            $reservation = $this->makeReservation();
            Event::dispatch(new ReservationCreated($reservation));

            AvailabilityChange::query()->delete();

            $this->dispatchEventAndCatchException(new $eventClass($reservation));

            $this->assertDatabaseHas('resrv_availability_changes', ['reason' => $reason]);
        }
    }

    public function test_rows_skipped_by_the_pending_guard_are_not_logged()
    {
        $reservation = $this->makeReservation();

        Event::dispatch(new ReservationCreated($reservation));
        // A duplicate hold is skipped by addToPending's guard — no availability change, no log row.
        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseCount('resrv_availability_changes', 2);
    }

    public function test_parent_reservations_log_one_batch_per_child()
    {
        $parent = Reservation::factory()->create([
            'item_id' => $this->item->id(),
            'type' => 'parent',
        ]);

        ChildReservation::factory()
            ->count(2)
            ->withRate($this->rate->id)
            ->create([
                'reservation_id' => $parent->id,
                'quantity' => 1,
                'date_start' => today()->toIso8601String(),
                'date_end' => today()->addDay()->toIso8601String(),
            ]);

        Event::dispatch(new ReservationCreated($parent));

        // Two children × one date each: two rows, one batch per child, every row recording
        // the PARENT booking id — child ids live in their own sequence and would collide
        // with unrelated reservations in the CP reservation_id filter.
        $this->assertDatabaseCount('resrv_availability_changes', 2);
        $this->assertCount(2, AvailabilityChange::pluck('batch')->unique());
        $this->assertEquals([$parent->id], AvailabilityChange::pluck('reservation_id')->unique()->all());

        $this->assertDatabaseHas('resrv_availability_changes', [
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'old_value' => 3,
            'new_value' => 2,
            'reason' => 'reservation_created',
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'old_value' => 2,
            'new_value' => 1,
        ]);
    }

    public function test_nothing_is_logged_when_the_toggle_is_off()
    {
        Config::set('resrv-config.enable_activity_log', false);

        $reservation = $this->makeReservation();
        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseCount('resrv_availability_changes', 0);
        // The actual availability write still happened.
        $this->assertDatabaseHas('resrv_availabilities', ['available' => 2]);
    }
}

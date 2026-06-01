<?php

namespace Reach\StatamicResrv\Tests\Jobs;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ExpireReservationsTest extends TestCase
{
    protected $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = $this->makeStatamicItem();
        $this->travelTo(today()->setHour(12));
        Config::set('resrv-config.minutes_to_hold', 30);
    }

    private function pendingReservation(int $createdMinutesAgo): Reservation
    {
        return Reservation::factory()->withCustomer()->create([
            'item_id' => $this->item->id(),
            'status' => 'pending',
            'created_at' => now()->subMinutes($createdMinutesAgo),
        ]);
    }

    public function test_expires_pending_reservations_past_the_hold_window_but_keeps_fresh_ones()
    {
        Event::fake([ReservationExpired::class]);

        $stale = $this->pendingReservation(45);
        $fresh = $this->pendingReservation(5);

        (new ExpireReservations)->handle();

        $this->assertEquals('expired', $stale->fresh()->status);
        $this->assertEquals('pending', $fresh->fresh()->status);

        Event::assertDispatchedTimes(ReservationExpired::class, 1);
        Event::assertDispatched(ReservationExpired::class, fn ($event) => $event->reservation->id === $stale->id);
    }

    public function test_does_not_expire_non_pending_reservations()
    {
        Event::fake([ReservationExpired::class]);

        $confirmed = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->item->id(),
            'status' => 'confirmed',
            'created_at' => now()->subMinutes(120),
        ]);

        (new ExpireReservations)->handle();

        $this->assertEquals('confirmed', $confirmed->fresh()->status);
        Event::assertNotDispatched(ReservationExpired::class);
    }

    public function test_does_nothing_when_minutes_to_hold_is_disabled()
    {
        Config::set('resrv-config.minutes_to_hold', false);
        Event::fake([ReservationExpired::class]);

        $stale = $this->pendingReservation(120);

        (new ExpireReservations)->handle();

        $this->assertEquals('pending', $stale->fresh()->status);
        Event::assertNotDispatched(ReservationExpired::class);
    }

    public function test_prune_filters_stale_rows_in_sql_rather_than_loading_every_pending_row()
    {
        Event::fake([ReservationExpired::class]);

        $this->pendingReservation(45);

        DB::enableQueryLog();
        (new ExpireReservations)->handle();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The prune query must constrain created_at in SQL, not load all PENDING rows first.
        $hasFilteredPrune = collect($queries)->contains(fn ($query) => str_contains($query['query'], 'resrv_reservations')
            && str_contains($query['query'], 'status')
            && str_contains($query['query'], 'created_at')
        );

        $this->assertTrue(
            $hasFilteredPrune,
            'The pending prune must constrain created_at in SQL so it does not load every PENDING row.'
        );
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservation.index'));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title);
    }

    public function test_reservation_listing_eager_loads_relations_without_n_plus_one()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        // Warm framework/config caches (blueprint, resrv_rates_exist) so the two measurements
        // below differ only by per-row relation loading, not first-hit caching.
        $this->get(cp_route('resrv.reservation.index'))->assertOk();

        $queriesForOneRow = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        Reservation::factory(3, ['item_id' => $item->id()])->withCustomer()->create();

        $queriesForFourRows = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        // With eager loading the query count is independent of the number of rows. An N+1
        // regression would make the four-row listing run several extra queries per added row
        // (customer, extras, options, childs.rate).
        $this->assertSame(
            $queriesForOneRow,
            $queriesForFourRows,
            'The reservations listing should run a constant number of queries regardless of row count.'
        );
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_can_show_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservation.show', $reservation->id));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title)->assertSee($reservation->customer->email);
    }

    public function test_can_show_child_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'type' => 'parent',
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $child = ChildReservation::factory([
            'reservation_id' => $reservation->id,
        ])->create();

        $response = $this->get(cp_route('resrv.reservation.show', $reservation->id));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('resrv::Reservations/Show')
                ->has('reservation.childs', 1)
                ->where('reservation.childs.0.date_end', $child->date_end->format('d-m-Y H:i'))
                ->where('reservation.entry.title', $item->title)
            );
    }

    public function test_can_refund_reservations()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $payload = [
            'id' => $reservation->id,
        ];

        $response = $this->patch(cp_route('resrv.reservation.refund', $payload));

        $response->assertStatus(200)->assertSee($reservation->id);
        Mail::assertSent(ReservationRefunded::class);
    }

    public function test_refund_on_already_refunded_reservation_short_circuits_before_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(409)->assertSee('already been refunded');
        Mail::assertNothingSent();
    }

    public function test_refund_on_expired_reservation_is_rejected_before_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(422)->assertSee('Cannot refund');
        Mail::assertNothingSent();
    }

    public function test_can_query_reservations_calendar_json()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservations.calendar.list').'?start='.urlencode(now()->toIso8601String()).'&end='.urlencode(now()->addMonth()->toIso8601String()));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title);
    }

    public function test_can_show_reservations_calendar()
    {
        $response = $this->get(cp_route('resrv.reservations.calendar'));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('resrv::Reservations/Calendar'));
    }
}

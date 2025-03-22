<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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

        $response->assertStatus(200)->assertSee($child->date_end->format('d-m-Y H:i'))->assertSee('Related reservations')->assertSee($item->title);
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

    public function test_can_query_reservations_calendar_json()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservations.calendar.list').'?start="'.now()->toIso8601String().'&end='.now()->addMonth()->toIso8601String());

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title);
    }

    public function test_can_show_reservations_calendar()
    {
        $response = $this->get(cp_route('resrv.reservations.calendar'));

        $response->assertStatus(200)->assertSee('Reservations Calendar');
    }
}

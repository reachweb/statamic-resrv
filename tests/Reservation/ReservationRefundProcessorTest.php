<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationRefundProcessorTest extends TestCase
{
    protected function makeConfirmedReservation(): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create([
            'status' => 'confirmed',
            'item_id' => $item->id(),
            'payment_id' => 'pi_123',
        ]);
    }

    public function test_refunds_through_the_gateway_and_dispatches_the_event()
    {
        Event::fake([ReservationRefunded::class]);

        $reservation = $this->makeConfirmedReservation();

        $this->mockRefundGateway();

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertSame('refunded', $reservation->status);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
        Event::assertDispatched(ReservationRefunded::class);
    }

    public function test_skips_the_gateway_when_a_concurrent_caller_already_refunded()
    {
        Event::fake([ReservationRefunded::class]);

        $reservation = $this->makeConfirmedReservation();

        // A stale in-memory model that still believes the reservation is confirmed, while
        // a concurrent caller has already won the refund on the database row.
        $stale = Reservation::find($reservation->id);
        Reservation::where('id', $reservation->id)->update(['status' => 'refunded']);

        $this->forbidGatewayRefunds();

        $changed = app(ReservationRefundProcessor::class)->refund($stale);

        $this->assertFalse($changed);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_rolls_back_the_status_when_the_gateway_refund_fails()
    {
        Event::fake([ReservationRefunded::class]);

        $reservation = $this->makeConfirmedReservation();

        $this->mockRefundGateway(new RefundFailedException('No such payment intent.'));

        try {
            app(ReservationRefundProcessor::class)->refund($reservation);
            $this->fail('Expected RefundFailedException was not thrown.');
        } catch (RefundFailedException) {
        }

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_rejects_a_reservation_that_cannot_transition_to_refunded()
    {
        Event::fake([ReservationRefunded::class]);

        $reservation = $this->makeConfirmedReservation();
        Reservation::where('id', $reservation->id)->update(['status' => 'expired']);
        $reservation->refresh();

        $this->forbidGatewayRefunds();

        $this->expectException(InvalidStateTransition::class);

        app(ReservationRefundProcessor::class)->refund($reservation);
    }
}

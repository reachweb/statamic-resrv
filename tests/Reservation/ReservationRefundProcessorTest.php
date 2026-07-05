<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
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

    public function test_cancel_without_refund_lands_in_cancelled_and_never_touches_the_gateway()
    {
        Event::fake([ReservationCancelled::class, ReservationRefunded::class]);

        $reservation = $this->makeConfirmedReservation();

        // The payment stays with the business — no gateway interaction of any kind.
        $this->forbidGatewayRefunds();

        $changed = app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation);

        $this->assertTrue($changed);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
        Event::assertDispatched(ReservationCancelled::class);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_cancel_without_refund_returns_false_when_a_concurrent_caller_already_cancelled()
    {
        Event::fake([ReservationCancelled::class]);

        $reservation = $this->makeConfirmedReservation();

        $stale = Reservation::find($reservation->id);
        Reservation::where('id', $reservation->id)->update(['status' => 'cancelled']);

        $changed = app(ReservationRefundProcessor::class)->cancelWithoutRefund($stale);

        $this->assertFalse($changed);
        Event::assertNotDispatched(ReservationCancelled::class);
    }

    public function test_refund_routes_no_charge_bookings_to_cancelled()
    {
        Event::fake([ReservationCancelled::class, ReservationRefunded::class]);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'status' => 'partner',
            'item_id' => $item->id(),
            'payment_id' => '',
        ]);

        $this->forbidGatewayRefunds();

        // The CP "refund" of a booking whose gateway holds no charge is really a void:
        // nothing can be returned, so it must end CANCELLED with the cancelled event chain.
        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
        Event::assertDispatched(ReservationCancelled::class);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_refund_expires_a_pending_no_charge_reservation_instead_of_cancelling()
    {
        Event::fake([ReservationExpired::class, ReservationCancelled::class, ReservationRefunded::class]);

        $item = $this->makeStatamicItem();

        // Abandoned zero-payment checkout: a mid-checkout hold, not a booking. CANCELLED is
        // unreachable from PENDING, so the refund action must release the hold the way the
        // expiry sweep would — through the expired chain, which never emails the customer.
        $reservation = Reservation::factory()->withCustomer()->create([
            'status' => 'pending',
            'item_id' => $item->id(),
            'payment_id' => '',
            'payment' => 0,
        ]);

        $this->forbidGatewayRefunds();

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertSame('expired', $reservation->status);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'expired',
        ]);
        Event::assertDispatched(ReservationExpired::class);
        Event::assertNotDispatched(ReservationCancelled::class);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_fails_closed_when_the_recorded_gateway_is_no_longer_configured()
    {
        Event::fake([ReservationRefunded::class]);

        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'status' => 'confirmed',
            'item_id' => $item->id(),
            'payment_id' => 'pi_123',
            'payment_gateway' => 'legacy-stripe',
        ]);

        // Substituting the current default would hand this reservation's foreign
        // payment_id to a provider that never charged it — the refund must abort.
        try {
            app(ReservationRefundProcessor::class)->refund($reservation);
            $this->fail('Expected UnknownPaymentGateway was not thrown.');
        } catch (UnknownPaymentGateway $e) {
            $this->assertSame('legacy-stripe', $e->gateway);
        }

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
        Event::assertNotDispatched(ReservationRefunded::class);
    }

    public function test_blank_recorded_gateway_still_falls_back_to_the_default()
    {
        Event::fake([ReservationRefunded::class]);

        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'status' => 'confirmed',
            'item_id' => $item->id(),
            'payment_id' => 'pi_123',
            'payment_gateway' => '',
        ]);

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
        Event::assertDispatched(ReservationRefunded::class);
    }
}

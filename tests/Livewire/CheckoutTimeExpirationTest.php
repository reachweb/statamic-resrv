<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Exceptions\ReservationExpiredException;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class CheckoutTimeExpirationTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        Config::set('resrv-config.minutes_to_hold', 30);
    }

    public function test_get_reservation_throws_expired_when_past_minutes_to_hold()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->travel(31)->minutes();

        $component = new Checkout;

        $this->expectException(ReservationExpiredException::class);
        $component->getReservation();
    }

    public function test_get_reservation_returns_normally_within_hold_window()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->travel(15)->minutes();

        $component = new Checkout;

        $result = $component->getReservation();
        $this->assertEquals($reservation->id, $result->id);
    }

    public function test_get_reservation_expires_past_hold_window_and_flips_status_to_expired()
    {
        Event::fake([ReservationExpired::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_time_expire',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->travel(31)->minutes();

        $component = new Checkout;

        try {
            $component->getReservation();
        } catch (ReservationExpiredException) {
            // expected
        }

        $fresh = $reservation->fresh();
        $this->assertEquals('expired', $fresh->status);
        $this->assertSame('', $fresh->payment_id);
        $this->assertSame('', $fresh->payment_gateway);
        Event::assertDispatched(ReservationExpired::class);
    }

    public function test_get_reservation_expires_cancels_stripe_intent()
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_time_expire_cancel',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->travel(31)->minutes();

        $component = new Checkout;

        try {
            $component->getReservation();
        } catch (ReservationExpiredException) {
            // expected
        }

        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertEquals('pi_time_expire_cancel', $gateway->cancelledIntents[0]['payment_id']);
    }
}

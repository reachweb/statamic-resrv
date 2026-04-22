<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class StateMachineTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
    }

    private function reservation(string $status): Reservation
    {
        return Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => $status,
        ]);
    }

    public function test_transition_to_same_state_is_idempotent_no_op()
    {
        foreach (['pending', 'confirmed', 'expired', 'refunded', 'partner'] as $status) {
            $reservation = $this->reservation($status);
            $originalUpdatedAt = $reservation->updated_at;

            $changed = $reservation->transitionTo(ReservationStatus::from($status));

            $this->assertFalse($changed, "Same-state transition for {$status} should return false");
            $this->assertEquals($status, $reservation->fresh()->status, "Status should remain {$status}");
            $this->assertEquals($originalUpdatedAt, $reservation->fresh()->updated_at, "Same-state transition should not write to the row for {$status}");
        }
    }

    public function test_transition_returns_true_when_state_actually_changes()
    {
        $reservation = $this->reservation('pending');

        $this->assertTrue($reservation->transitionTo(ReservationStatus::CONFIRMED));
    }

    public function test_pending_can_transition_to_confirmed()
    {
        $reservation = $this->reservation('pending');

        $reservation->transitionTo(ReservationStatus::CONFIRMED);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
    }

    public function test_pending_can_transition_to_expired()
    {
        $reservation = $this->reservation('pending');

        $reservation->transitionTo(ReservationStatus::EXPIRED);

        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    public function test_pending_can_transition_to_refunded()
    {
        $reservation = $this->reservation('pending');

        $reservation->transitionTo(ReservationStatus::REFUNDED);

        $this->assertEquals('refunded', $reservation->fresh()->status);
    }

    public function test_pending_can_transition_to_partner()
    {
        $reservation = $this->reservation('pending');

        $reservation->transitionTo(ReservationStatus::PARTNER);

        $this->assertEquals('partner', $reservation->fresh()->status);
    }

    public function test_confirmed_can_only_transition_to_refunded()
    {
        $reservation = $this->reservation('confirmed');

        $reservation->transitionTo(ReservationStatus::REFUNDED);

        $this->assertEquals('refunded', $reservation->fresh()->status);
    }

    public function test_partner_can_only_transition_to_refunded()
    {
        $reservation = $this->reservation('partner');

        $reservation->transitionTo(ReservationStatus::REFUNDED);

        $this->assertEquals('refunded', $reservation->fresh()->status);
    }

    public function test_expired_to_confirmed_is_rejected()
    {
        $reservation = $this->reservation('expired');

        $this->expectException(InvalidStateTransition::class);

        $reservation->transitionTo(ReservationStatus::CONFIRMED);
    }

    public function test_refunded_to_confirmed_is_rejected()
    {
        $reservation = $this->reservation('refunded');

        $this->expectException(InvalidStateTransition::class);

        $reservation->transitionTo(ReservationStatus::CONFIRMED);
    }

    public function test_refunded_to_any_other_state_is_rejected()
    {
        foreach ([ReservationStatus::PENDING, ReservationStatus::CONFIRMED, ReservationStatus::EXPIRED, ReservationStatus::PARTNER] as $target) {
            $reservation = $this->reservation('refunded');

            try {
                $reservation->transitionTo($target);
                $this->fail("Expected transition from refunded to {$target->value} to be rejected.");
            } catch (InvalidStateTransition $e) {
                $this->assertEquals('refunded', $reservation->fresh()->status);
            }
        }
    }

    public function test_rejected_transition_does_not_mutate_status()
    {
        $reservation = $this->reservation('expired');

        try {
            $reservation->transitionTo(ReservationStatus::CONFIRMED);
        } catch (InvalidStateTransition) {
            // expected
        }

        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    public function test_expired_is_terminal()
    {
        $this->assertTrue(ReservationStatus::EXPIRED->isTerminal());
    }

    public function test_refunded_is_terminal()
    {
        $this->assertTrue(ReservationStatus::REFUNDED->isTerminal());
    }

    public function test_pending_is_not_terminal()
    {
        $this->assertFalse(ReservationStatus::PENDING->isTerminal());
    }

    public function test_expire_on_pending_reservation_flips_status_clears_payment_fields_and_dispatches_event()
    {
        Event::fake([ReservationExpired::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_expire_me',
            'payment_gateway' => 'fake',
        ]);

        $reservation->expire();

        $fresh = $reservation->fresh();
        $this->assertEquals('expired', $fresh->status);
        $this->assertSame('', $fresh->payment_id);
        $this->assertSame('', $fresh->payment_gateway);
        Event::assertDispatched(ReservationExpired::class, fn ($e) => $e->reservation->id === $reservation->id);
    }

    public function test_expire_on_non_pending_reservation_is_noop()
    {
        Event::fake([ReservationExpired::class]);

        foreach (['confirmed', 'expired', 'refunded', 'partner'] as $status) {
            $reservation = Reservation::factory()->withCustomer()->create([
                'item_id' => $this->entries->first()->id(),
                'status' => $status,
                'payment_id' => 'pi_test_noop_'.$status,
                'payment_gateway' => 'fake',
            ]);

            $reservation->expire();

            $fresh = $reservation->fresh();
            $this->assertEquals($status, $fresh->status, "Expiring a {$status} reservation must not change status");
            $this->assertEquals('pi_test_noop_'.$status, $fresh->payment_id, "Expiring a {$status} reservation must not clear payment_id");
            $this->assertEquals('fake', $fresh->payment_gateway, "Expiring a {$status} reservation must not clear payment_gateway");
        }

        Event::assertNotDispatched(ReservationExpired::class);
    }

    public function test_expire_calls_gateway_cancel_with_the_old_payment_id()
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_to_cancel',
            'payment_gateway' => 'fake',
        ]);

        $reservation->expire();

        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertEquals('pi_test_to_cancel', $gateway->cancelledIntents[0]['payment_id']);
        $this->assertEquals($reservation->id, $gateway->cancelledIntents[0]['reservation_id']);
    }

    public function test_expire_without_payment_id_skips_gateway_cancel()
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => '',
            'payment_gateway' => '',
        ]);

        $reservation->expire();

        $this->assertEquals('expired', $reservation->fresh()->status);
        $this->assertCount(0, $gateway->cancelledIntents);
    }
}

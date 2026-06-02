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

    public function test_duration_does_not_mutate_the_cached_dates()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'date_start' => '2026-06-01 14:30:00',
            'date_end' => '2026-06-04 09:15:00',
        ]);

        $this->assertEquals(3, $reservation->duration());

        // duration() must not zero the time component of the model's cached Carbon instances (L11).
        $this->assertEquals('2026-06-01 14:30:00', $reservation->date_start->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-04 09:15:00', $reservation->date_end->format('Y-m-d H:i:s'));
    }

    public function test_find_by_payment_id_ignores_reservations_with_a_cleared_payment_id()
    {
        // expire()/cancelActiveIntent clear payment_id to '', so a lookup with an empty id must match
        // nothing rather than an arbitrary cleared reservation (L9).
        $cleared = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
            'payment_id' => '',
        ]);

        $live = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_live_123',
        ]);

        // An empty/cleared id matches nothing...
        $this->assertNull(Reservation::findByPaymentId('')->first());
        // ...while a real intent id still resolves to its reservation.
        $this->assertEquals($live->id, Reservation::findByPaymentId('pi_live_123')->first()?->id);
    }

    public function test_reservation_does_not_mass_assign_guarded_columns()
    {
        // L12: id/timestamps are not fillable, so a future fill()/update() with untrusted input
        // can't overwrite the primary key; legitimately-writable columns still fill.
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
        ]);

        $originalId = $reservation->id;

        $reservation->fill(['id' => $originalId + 999, 'status' => 'confirmed']);

        $this->assertEquals($originalId, $reservation->id);
        $this->assertEquals('confirmed', $reservation->status);
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

    public function test_tolerant_transition_returns_false_instead_of_throwing_on_invalid_transition()
    {
        // Reactive callers (webhooks/checkout) pass tolerant: true so a row that changed under the
        // lock — e.g. a concurrent expire() between their in-memory pre-check and the lock — is a
        // no-op rather than an uncaught exception that surfaces as an HTTP 500 / webhook retry (L10).
        $reservation = $this->reservation('expired');

        $changed = $reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true);

        $this->assertFalse($changed);
        $this->assertEquals('expired', $reservation->fresh()->status);
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

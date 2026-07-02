<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Livewire\Traits\HandlesReservationConfirmation;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Models\ReservationLog;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationLogTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    public $entry;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.enable_activity_log', true);

        Mail::fake();

        $this->entry = $this->makeStatamicItemWithAvailability();
        $this->travelTo(today()->setHour(12));
    }

    private function makeReservation(array $attributes = []): Reservation
    {
        return Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $this->entry->id(),
        ], $attributes));
    }

    private function confirmViaTrait(Reservation $reservation, ReservationStatus $target): bool
    {
        $confirmer = new class
        {
            use HandlesReservationConfirmation;

            public function confirm(Reservation $reservation, ReservationStatus $target): bool
            {
                return $this->confirmOrAlreadyConfirmed($reservation, $target);
            }
        };

        return $confirmer->confirm($reservation, $target);
    }

    public function test_reservation_created_logs_a_checkout_started_entry()
    {
        $reservation = $this->makeReservation();

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'reference' => $reservation->reference,
            'status_from' => null,
            'status_to' => 'pending',
            'reason' => 'checkout_started',
            'actor_id' => null,
        ]);
    }

    public function test_reservation_created_handles_an_enum_status_on_the_in_memory_model()
    {
        // The live checkout dispatches ReservationCreated with a model whose status attribute
        // still holds the ReservationStatus enum it was created with, not the persisted string.
        $reservation = $this->makeReservation();
        $reservation->status = ReservationStatus::PENDING;

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_to' => 'pending',
            'reason' => 'checkout_started',
        ]);
    }

    public function test_checkout_confirmation_logs_the_transition()
    {
        $reservation = $this->makeReservation();

        $this->assertTrue($this->confirmViaTrait($reservation, ReservationStatus::CONFIRMED));

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'pending',
            'status_to' => 'confirmed',
            'reason' => 'checkout_confirmed',
        ]);
    }

    public function test_checkout_confirmation_to_partner_logs_the_partner_state()
    {
        $reservation = $this->makeReservation();

        $this->assertTrue($this->confirmViaTrait($reservation, ReservationStatus::PARTNER));

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'pending',
            'status_to' => 'partner',
            'reason' => 'checkout_confirmed',
        ]);
    }

    public function test_a_failed_confirmation_race_logs_nothing()
    {
        $reservation = $this->makeReservation(['status' => 'expired']);

        $this->assertFalse($this->confirmViaTrait($reservation, ReservationStatus::CONFIRMED));

        $this->assertDatabaseCount('resrv_reservation_logs', 0);
    }

    public function test_webhook_confirmation_logs_the_gateway_context()
    {
        $reservation = $this->makeReservation(['payment_id' => 'fake_intent_123']);

        $this->post(route('resrv.webhook.store', [
            'reservation_id' => $reservation->id,
            'status' => 'success',
        ]))->assertOk();

        $this->assertEquals('confirmed', $reservation->fresh()->status);

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'pending',
            'status_to' => 'confirmed',
            'reason' => 'webhook_confirmed',
        ]);

        $log = ReservationLog::forReservation($reservation->id)->first();
        $this->assertEquals('fake_intent_123', $log->context['payment_id']);
        $this->assertNotEmpty($log->context['gateway']);
    }

    public function test_expiring_a_stale_reservation_logs_an_expired_entry()
    {
        $reservation = $this->makeReservation([
            'created_at' => now()->subMinutes(60),
        ]);

        ExpireReservations::dispatchSync();

        $this->assertEquals('expired', $reservation->fresh()->status);

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'pending',
            'status_to' => 'expired',
            'reason' => 'expired',
        ]);
    }

    public function test_a_reservation_cancelled_dispatch_logs_the_current_state()
    {
        // The addon never dispatches ReservationCancelled itself — this covers
        // site-level code using the public event.
        $reservation = $this->makeReservation();

        Event::dispatch(new ReservationCancelled($reservation));

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'pending',
            'status_to' => 'pending',
            'reason' => 'cancelled',
        ]);
    }

    public function test_cp_refund_logs_the_actor_snapshot_from_confirmed()
    {
        $this->signInAdmin();

        $reservation = $this->makeReservation([
            'status' => 'confirmed',
            'payment_id' => 'abcdef',
        ]);

        $this->patch(cp_route('resrv.reservation.refund'), ['id' => $reservation->id])
            ->assertOk();

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'confirmed',
            'status_to' => 'refunded',
            'reason' => 'cp_refund',
            'actor_id' => '1',
            'actor_name' => 'test@test.com',
        ]);
    }

    public function test_cp_refund_logs_the_status_observed_under_the_transition_lock()
    {
        $this->signInAdmin();

        // The controller pre-check reads PENDING, then a webhook confirm lands during the
        // gateway refund call — before the transition takes the row lock. The log must
        // record the observed confirmed → refunded, not the stale pending → refunded.
        $reservation = $this->makeReservation([
            'status' => 'pending',
            'payment_id' => 'abcdef',
        ]);

        $gateway = \Mockery::mock(PaymentInterface::class);
        $gateway->shouldReceive('refund')->andReturnUsing(function ($refunding) {
            Reservation::whereKey($refunding->id)->update(['status' => 'confirmed']);

            return true;
        });

        $this->mock(PaymentGatewayManager::class, function ($mock) use ($gateway) {
            $mock->shouldReceive('forReservation')->andReturn($gateway);
        });

        $this->patch(cp_route('resrv.reservation.refund'), ['id' => $reservation->id])
            ->assertOk();

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'confirmed',
            'status_to' => 'refunded',
            'reason' => 'cp_refund',
        ]);
    }

    public function test_cp_refund_logs_the_partner_from_state()
    {
        $this->signInAdmin();

        $reservation = $this->makeReservation([
            'status' => 'partner',
            'payment_id' => '',
            'payment' => 0,
        ]);

        $this->patch(cp_route('resrv.reservation.refund'), ['id' => $reservation->id])
            ->assertOk();

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'status_from' => 'partner',
            'status_to' => 'refunded',
            'reason' => 'cp_refund',
        ]);
    }

    public function test_nothing_is_logged_when_the_toggle_is_off()
    {
        Config::set('resrv-config.enable_activity_log', false);

        $reservation = $this->makeReservation(['payment_id' => 'fake_intent_123']);

        Event::dispatch(new ReservationCreated($reservation));
        Event::dispatch(new ReservationCancelled($reservation));

        $this->post(route('resrv.webhook.store', [
            'reservation_id' => $reservation->id,
            'status' => 'success',
        ]))->assertOk();

        $this->assertDatabaseCount('resrv_reservation_logs', 0);
    }
}

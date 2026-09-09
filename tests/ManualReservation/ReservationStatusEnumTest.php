<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingPaymentReservation(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $item->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
        ], $attributes));
    }

    public function test_awaiting_payment_can_transition_to_confirmed_and_cancelled_only()
    {
        $this->assertTrue(ReservationStatus::AWAITING_PAYMENT->canTransitionTo(ReservationStatus::CONFIRMED));
        $this->assertTrue(ReservationStatus::AWAITING_PAYMENT->canTransitionTo(ReservationStatus::CANCELLED));

        foreach ([
            ReservationStatus::PENDING,
            ReservationStatus::EXPIRED,
            ReservationStatus::REFUNDED,
            ReservationStatus::PARTNER,
            ReservationStatus::WEBHOOK,
            ReservationStatus::COMPLETED,
        ] as $target) {
            $this->assertFalse(
                ReservationStatus::AWAITING_PAYMENT->canTransitionTo($target),
                "AWAITING_PAYMENT must not transition to {$target->value}"
            );
        }
    }

    public function test_no_status_can_transition_into_awaiting_payment()
    {
        foreach (ReservationStatus::cases() as $status) {
            if ($status === ReservationStatus::AWAITING_PAYMENT) {
                continue;
            }

            $this->assertFalse(
                $status->canTransitionTo(ReservationStatus::AWAITING_PAYMENT),
                "{$status->value} must not transition into awaiting_payment — it is only created directly"
            );
        }
    }

    public function test_awaiting_payment_group_membership()
    {
        $this->assertFalse(ReservationStatus::AWAITING_PAYMENT->isTerminal());
        $this->assertNotContains(ReservationStatus::AWAITING_PAYMENT->value, ReservationStatus::live());
        // Its hold releases stock asynchronously, so it must block absolute availability edits like PENDING.
        $this->assertContains(ReservationStatus::AWAITING_PAYMENT->value, ReservationStatus::inFlight());
        $this->assertNotContains(ReservationStatus::AWAITING_PAYMENT->value, ReservationStatus::terminal());
    }

    public function test_awaiting_payment_reservation_transitions_to_confirmed()
    {
        $reservation = $this->awaitingPaymentReservation();

        $this->assertTrue($reservation->transitionTo(ReservationStatus::CONFIRMED));
        $this->assertEquals('confirmed', $reservation->fresh()->status);
    }

    public function test_awaiting_payment_reservation_transitions_to_cancelled()
    {
        $reservation = $this->awaitingPaymentReservation();

        $this->assertTrue($reservation->transitionTo(ReservationStatus::CANCELLED));
        $this->assertEquals('cancelled', $reservation->fresh()->status);
    }

    public function test_awaiting_payment_reservation_rejects_expiry_and_refund_transitions()
    {
        foreach ([ReservationStatus::EXPIRED, ReservationStatus::REFUNDED, ReservationStatus::PENDING] as $target) {
            $reservation = $this->awaitingPaymentReservation();

            try {
                $reservation->transitionTo($target);
                $this->fail("Expected transition from awaiting_payment to {$target->value} to be rejected.");
            } catch (InvalidStateTransition $e) {
                $this->assertEquals('awaiting_payment', $reservation->fresh()->status);
            }
        }
    }

    public function test_expire_on_awaiting_payment_reservation_is_a_noop()
    {
        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_awaiting_hold',
            'payment_gateway' => 'fake',
        ]);

        $reservation->expire();

        $fresh = $reservation->fresh();
        $this->assertEquals('awaiting_payment', $fresh->status);
        $this->assertEquals('pi_awaiting_hold', $fresh->payment_id);
    }

    public function test_awaiting_payment_reservation_survives_housekeeping()
    {
        $reservation = $this->awaitingPaymentReservation();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update([
            'created_at' => now()->subDays(365),
            'updated_at' => now()->subDays(365),
        ]);

        $this->artisan('resrv:housekeeping', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 0 expired reservation')
            ->assertExitCode(0);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 0 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id]);
    }

    public function test_awaiting_payment_reservation_is_not_picked_up_by_abandoned_emails()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $this->awaitingPaymentReservation([
            'updated_at' => Carbon::yesterday(),
        ]);

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('No abandoned reservations found.')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_manual_reservation_columns_exist_with_defaults()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        $fresh = $reservation->fresh();
        $this->assertTrue($fresh->affects_availability);
        $this->assertNull($fresh->created_by);
        $this->assertNull($fresh->hold_expires_at);
        $this->assertNull($fresh->payment_request_email_sent_at);
    }

    public function test_manual_reservation_columns_are_fillable_and_cast()
    {
        $item = $this->makeStatamicItem();
        $holdExpiresAt = now()->addDays(3)->startOfSecond();

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'affects_availability' => false,
            'created_by' => 'user-id-1',
            'hold_expires_at' => $holdExpiresAt,
        ]);

        $fresh = $reservation->fresh();
        $this->assertFalse($fresh->affects_availability);
        $this->assertSame('user-id-1', $fresh->created_by);
        $this->assertTrue($holdExpiresAt->equalTo($fresh->hold_expires_at));
    }
}

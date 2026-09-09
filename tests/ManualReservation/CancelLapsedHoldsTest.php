<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled as ReservationCancelledEvent;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Mail\ReservationCancelled as ReservationCancelledMail;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class CancelLapsedHoldsTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.admin_email', 'admin@example.com');
    }

    private function overdueReservation(array $attributes = []): Reservation
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $rate = Rate::forEntry($entry->id())->first();

        return Reservation::factory()->withCustomer()->withRate($rate->id)->create(array_merge([
            'item_id' => $entry->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'hold_expires_at' => now()->subHour(),
        ], $attributes));
    }

    private function availableOn(string $itemId, $date): int
    {
        return (int) Availability::where('statamic_id', $itemId)
            ->where('date', '>=', $date->toDateString())
            ->where('date', '<', $date->copy()->addDay()->toDateString())
            ->first()
            ->available;
    }

    private function decrementFor(Reservation $reservation): void
    {
        (new Availability)->decrementAvailability(
            date_start: $reservation->date_start,
            date_end: $reservation->date_end,
            quantity: $reservation->quantity,
            statamic_id: $reservation->item_id,
            reservationId: $reservation->id,
            rateId: $reservation->rate_id,
        );
    }

    public function test_overdue_hold_is_cancelled_with_both_notifications_and_intent_cancel()
    {
        Mail::fake();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->overdueReservation([
            'affects_availability' => true,
            'payment_id' => 'pi_lapsed_hold',
            'payment_gateway' => 'fake',
        ]);
        $this->decrementFor($reservation);
        $this->assertEquals(1, $this->availableOn($reservation->item_id, today()));

        $this->artisan('resrv:cancel-lapsed-holds')
            ->expectsOutputToContain('Cancelled 1 lapsed hold(s).')
            ->assertSuccessful();

        $this->app->terminate();

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertEquals(2, $this->availableOn($reservation->item_id, today()));

        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertEquals('pi_lapsed_hold', $gateway->cancelledIntents[0]['payment_id']);

        // The verified-void intent's reference is dropped so payment_id readers can't report money never collected.
        $this->assertSame('', $reservation->fresh()->payment_id);

        Mail::assertSent(ReservationCancelledCustomer::class, function ($mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email)
                && $mail->context === ReservationCancelledEvent::CONTEXT_HOLD_LAPSED;
        });
        Mail::assertSent(ReservationCancelledMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === ReservationCancelledEvent::CONTEXT_HOLD_LAPSED;
        });

        // The lapsed wording actually renders in both bodies.
        $customerHtml = (new ReservationCancelledCustomer($reservation->fresh(), ReservationCancelledEvent::CONTEXT_HOLD_LAPSED))->render();
        $this->assertStringContainsString('payment hold lapsed', $customerHtml);
        $adminHtml = (new ReservationCancelledMail($reservation->fresh(), ReservationCancelledEvent::CONTEXT_HOLD_LAPSED))->render();
        $this->assertStringContainsString('payment hold lapsed', $adminHtml);

        // An opened-but-unpaid intent must not make the admin email claim a payment was retained.
        $this->assertStringNotContainsString('payment retained', $adminHtml);
        $this->assertStringNotContainsString('No action is required', $adminHtml);
    }

    public function test_stock_is_not_restored_when_the_flag_is_off()
    {
        Mail::fake();

        $reservation = $this->overdueReservation([
            'affects_availability' => false,
        ]);

        $this->artisan('resrv:cancel-lapsed-holds')->assertSuccessful();

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertEquals(2, $this->availableOn($reservation->item_id, today()));
    }

    public function test_future_and_unbounded_holds_are_untouched()
    {
        Mail::fake();

        $future = $this->overdueReservation(['hold_expires_at' => now()->addDay()]);
        $unbounded = $this->overdueReservation(['hold_expires_at' => null]);

        $this->artisan('resrv:cancel-lapsed-holds')
            ->expectsOutputToContain('Cancelled 0 lapsed hold(s).')
            ->assertSuccessful();

        $this->assertEquals('awaiting_payment', $future->fresh()->status);
        $this->assertEquals('awaiting_payment', $unbounded->fresh()->status);
    }

    public function test_dry_run_changes_nothing()
    {
        Mail::fake();

        $reservation = $this->overdueReservation();

        $this->artisan('resrv:cancel-lapsed-holds', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 lapsed hold(s) would be cancelled.')
            ->assertSuccessful();

        $this->assertEquals('awaiting_payment', $reservation->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_race_a_reservation_confirmed_between_query_and_lock_stays_confirmed()
    {
        Mail::fake();

        $reservation = $this->overdueReservation();

        // Regression: a webhook confirm between the candidate query and the lock must trip the in-transaction origin re-check.
        // The retrieved hook flips the row to CONFIRMED at DB level to simulate the cross-process race.
        Reservation::retrieved(function (Reservation $model) use ($reservation) {
            if ((int) $model->id === $reservation->id && $model->status === ReservationStatus::AWAITING_PAYMENT->value) {
                DB::table('resrv_reservations')
                    ->where('id', $reservation->id)
                    ->update(['status' => ReservationStatus::CONFIRMED->value]);
            }
        });

        $this->artisan('resrv:cancel-lapsed-holds')
            ->expectsOutputToContain('Cancelled 0 lapsed hold(s).')
            ->assertSuccessful();

        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Mail::assertNotSent(ReservationCancelledCustomer::class);
    }

    public function test_race_b_webhook_success_after_lapse_cancel_notifies_orphan()
    {
        Mail::fake();

        $reservation = $this->overdueReservation([
            'payment_id' => 'pi_lapsed_orphan',
            'payment_gateway' => 'fake',
        ]);

        $this->artisan('resrv:cancel-lapsed-holds')->assertSuccessful();
        $this->assertEquals('cancelled', $reservation->fresh()->status);

        // A payment landing after the sweep must keep CANCELLED and notify admins of the orphaned charge.
        $this->post(route('resrv.webhook.store', ['reservation_id' => $reservation->id, 'status' => 'success']))
            ->assertStatus(200);

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($mail) => $mail->hasTo('admin@example.com'));
    }

    public function test_frontend_cancellations_keep_the_existing_wording()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $entry->id(),
            'status' => 'confirmed',
        ]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation);

        $this->app->terminate();

        Mail::assertSent(ReservationCancelledCustomer::class, fn ($mail) => $mail->context === null);

        $html = (new ReservationCancelledCustomer($reservation->fresh()))->render();
        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringNotContainsString('payment hold lapsed', $html);
    }
}

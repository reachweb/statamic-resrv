<?php

namespace Reach\StatamicResrv\Tests\Affiliate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Jobs\SendRefundReservationEmails as SendRefundJob;
use Reach\StatamicResrv\Listeners\CancelAffiliateCommission;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateCommissionCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_registered_for_the_reservation_refunded_event()
    {
        Event::fake();

        Event::assertListening(ReservationRefunded::class, CancelAffiliateCommission::class);
    }

    public function test_refunding_a_confirmed_reservation_cancels_the_affiliate_commission()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_123']);

        $this->mockRefundGateway();

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertCommissionCancelled($reservation);
    }

    public function test_refunding_a_partner_reservation_cancels_the_affiliate_commission()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'partner', 'payment_id' => '']);

        // A partner booking holds no charge, so the refund must never touch the gateway.
        $this->forbidGatewayRefunds();

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertCommissionCancelled($reservation);
    }

    public function test_customer_self_cancellation_cancels_the_affiliate_commission()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 15]);
        $reservation = $this->makeAffiliateReservation($affiliate, [
            'status' => 'confirmed',
            'payment_id' => 'pi_123',
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 2,
        ], 15);

        // Guard the precondition so the test fails loudly if cancelByCustomer() stops enforcing it.
        $this->assertTrue($reservation->canBeCancelledByCustomer());

        $this->mockRefundGateway();

        $changed = app(ReservationRefundProcessor::class)->cancelByCustomer($reservation);

        $this->assertTrue($changed);
        $this->assertCommissionCancelled($reservation);
    }

    public function test_no_refund_cancellation_of_a_paid_booking_keeps_the_commission()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_123']);

        $changed = app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation);

        // The business kept the payment, so the commission is still owed — only refunds
        // (money returned) and no-charge voids (no revenue existed) cancel it.
        $this->assertTrue($changed);
        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id, 'status' => 'cancelled']);
        $this->assertNull($this->pivotRow($reservation->id)->cancelled_at);
    }

    public function test_no_charge_void_cancels_the_affiliate_commission()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'partner', 'payment_id' => '']);

        $this->forbidGatewayRefunds();

        // Routed through refund() the way the CP does it: no charge → CANCELLED, and since
        // no revenue ever existed for the booking, the commission is voided.
        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id, 'status' => 'cancelled']);
        $this->assertCommissionCancelled($reservation);
    }

    public function test_commission_is_cancelled_even_when_availability_restore_throws()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_123']);

        $this->mockRefundGateway();

        // Availability restore throws, halting the chain; commission cancellation runs first, so it survives.
        $this->mock(Availability::class, function ($mock) {
            $mock->shouldReceive('incrementAvailability')->andThrow(new \RuntimeException('availability boom'));
        });

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertCommissionCancelled($reservation);
    }

    public function test_availability_restore_runs_before_the_refund_email()
    {
        Mail::fake();
        Bus::fake([SendRefundJob::class]);

        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_123']);

        $this->mockRefundGateway();

        // Availability throws and halts the chain; the email listener sits after it, so it is never
        // reached — proving the critical inventory restore is not gated behind email dispatch.
        $this->mock(Availability::class, function ($mock) {
            $mock->shouldReceive('incrementAvailability')->andThrow(new \RuntimeException('availability boom'));
        });

        app(ReservationRefundProcessor::class)->refund($reservation);

        Bus::assertNotDispatchedAfterResponse(SendRefundJob::class);
    }

    public function test_a_coupon_attributed_affiliate_commission_is_cancelled_on_refund()
    {
        Mail::fake();

        $item = $this->makeStatamicItem();

        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_123',
        ]);

        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);

        $this->mockRefundGateway();

        app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertCommissionCancelled($reservation);
    }

    public function test_commission_cancellation_is_idempotent_and_preserves_the_original_timestamp()
    {
        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['status' => 'refunded']);

        $original = Carbon::create(2020, 1, 1, 0, 0, 0);

        DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $reservation->id)
            ->update(['cancelled_at' => $original]);

        (new CancelAffiliateCommission)->handle(new ReservationRefunded($reservation));

        $this->assertEquals(
            '2020-01-01 00:00:00',
            Carbon::parse($this->pivotRow($reservation->id)->cancelled_at)->format('Y-m-d H:i:s'),
        );
    }

    public function test_refunding_a_reservation_without_an_affiliate_does_not_error()
    {
        Mail::fake();

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_123',
        ]);

        $this->mockRefundGateway();

        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertDatabaseCount('resrv_reservation_affiliate', 0);
    }

    public function test_payout_query_excludes_cancelled_commission_but_keeps_the_row_for_audit()
    {
        Mail::fake();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);

        $active = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_active']);
        $refunded = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_refunded']);

        $this->mockRefundGateway();

        app(ReservationRefundProcessor::class)->refund($refunded);

        // The full relation keeps both rows so the commission history stays auditable.
        $this->assertEquals(2, $affiliate->reservations()->count());

        // Cancellation only stamps cancelled_at; the original fee is preserved for audit.
        $cancelledRow = $this->pivotRow($refunded->id);
        $this->assertNotNull($cancelledRow->cancelled_at);
        $this->assertEquals(20, (float) $cancelledRow->fee);

        // A payout view that excludes cancelled commission only sees the live booking.
        $payableIds = $affiliate->reservations()->wherePivotNull('cancelled_at')->get()->pluck('id');

        $this->assertTrue($payableIds->contains($active->id));
        $this->assertFalse($payableIds->contains($refunded->id));
    }

    public function test_reservation_show_exposes_the_commission_cancelled_flag()
    {
        $this->signInAdmin();

        $affiliate = Affiliate::factory()->create(['fee' => 20]);

        $active = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed', 'payment_id' => 'pi_123']);
        $cancelled = $this->makeAffiliateReservation($affiliate, ['status' => 'refunded', 'payment_id' => 'pi_123']);

        DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $cancelled->id)
            ->update(['cancelled_at' => now()]);

        $this->get(cp_route('resrv.reservation.show', $active->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reservation.affiliate', fn (AssertableInertia $affiliate) => $affiliate
                    ->where('commission_cancelled', false)
                    ->has('name')
                    ->has('email')
                    ->has('fee')
                    ->has('fee_amount_formatted')
                )
            );

        $this->get(cp_route('resrv.reservation.show', $cancelled->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reservation.affiliate', fn (AssertableInertia $affiliate) => $affiliate
                    ->where('commission_cancelled', true)
                    ->has('fee')
                    ->has('fee_amount_formatted')
                    ->etc()
                )
            );
    }

    protected function makeAffiliateReservation(Affiliate $affiliate, array $attributes = [], float $fee = 20): Reservation
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $item->id(),
        ], $attributes));

        $reservation->affiliate()->attach($affiliate->id, ['fee' => $fee]);

        return $reservation;
    }

    protected function pivotRow(int $reservationId): ?object
    {
        return DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $reservationId)
            ->first();
    }

    protected function assertCommissionCancelled(Reservation $reservation): void
    {
        $this->assertTrue(
            DB::table('resrv_reservation_affiliate')
                ->where('reservation_id', $reservation->id)
                ->whereNotNull('cancelled_at')
                ->exists(),
            'Expected the affiliate commission to be marked cancelled.',
        );
    }
}

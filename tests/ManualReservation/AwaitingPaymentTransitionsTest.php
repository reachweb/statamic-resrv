<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed as ReservationConfirmedEvent;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Support\Str;

class AwaitingPaymentTransitionsTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.stripe_webhook_secret', 'whsec_test');
        Config::set('resrv-config.stripe_secret_key', 'sk_test');
        Config::set('resrv-config.admin_email', 'admin@example.com');
    }

    private function awaitingPaymentReservation(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $item->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
        ], $attributes));
    }

    private function awaitingPaymentReservationWithStock(int $available = 2, array $attributes = []): Reservation
    {
        $entry = $this->makeStatamicItemWithAvailability(available: $available);
        $rate = Rate::forEntry($entry->id())->first();

        return Reservation::factory()->withCustomer()->withRate($rate->id)->create(array_merge([
            'item_id' => $entry->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
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

    private function signedWebhookRequest(string $type, string $paymentIntentId, array $extraObjectData = []): Request
    {
        $payload = json_encode([
            'type' => $type,
            'data' => ['object' => array_merge(['id' => $paymentIntentId], $extraObjectData)],
        ]);

        $timestamp = time();
        $signature = sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test')
        );

        return Request::create('/resrv/api/webhook', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }

    public function test_webhook_success_confirms_an_awaiting_payment_reservation()
    {
        Event::fake([ReservationConfirmedEvent::class]);

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_awaiting_success',
            'payment_gateway' => 'fake',
        ]);

        $this->post(route('resrv.webhook.store', ['reservation_id' => $reservation->id, 'status' => 'success']))
            ->assertStatus(200);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmedEvent::class, function ($event) use ($reservation) {
            return $event->reservation->id === $reservation->id
                && $event->via === ReservationConfirmedEvent::VIA_WEBHOOK;
        });
    }

    public function test_stripe_webhook_amount_mismatch_leaves_awaiting_payment_untouched_and_notifies()
    {
        Mail::fake();
        Event::fake([ReservationConfirmedEvent::class]);

        $reservation = $this->awaitingPaymentReservation([
            'payment' => 50,
            'payment_surcharge' => 0,
            'payment_id' => 'pi_awaiting_mismatch',
            'payment_gateway' => 'stripe',
        ]);

        $request = $this->signedWebhookRequest('payment_intent.succeeded', 'pi_awaiting_mismatch', ['amount_received' => 9900]);

        $response = app(StripePaymentGateway::class)->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('awaiting_payment', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmedEvent::class);
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($m) => $m->hasTo('admin@example.com'));
    }

    public function test_stripe_canceled_webhook_never_expires_an_awaiting_payment_reservation()
    {
        // The canceled webhook only expires PENDING rows: an admin-created hold must survive its intent being cancelled.
        Mail::fake();

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_awaiting_canceled',
            'payment_gateway' => 'stripe',
        ]);

        $response = app(StripePaymentGateway::class)
            ->verifyPayment($this->signedWebhookRequest('payment_intent.canceled', 'pi_awaiting_canceled'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('awaiting_payment', $reservation->fresh()->status);
        $this->assertEquals('pi_awaiting_canceled', $reservation->fresh()->payment_id);
    }

    public function test_cp_confirm_payment_confirms_and_sends_confirmation_emails()
    {
        Mail::fake();
        $this->signInAdmin();
        Config::set('resrv-config.payment', 'fixed');

        $reservation = $this->awaitingPaymentReservation([
            'payment_gateway' => 'offline',
        ]);

        $response = $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertJson([
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        // Out-of-band money is not a card charge — the confirmation must not claim "credit card".
        Mail::assertSent(ReservationConfirmedMail::class, function ($mail) use ($reservation) {
            $html = $mail->render();

            return $mail->hasTo($reservation->customer->email)
                && str_contains($html, 'Amount already paid')
                && ! str_contains($html, 'credit card');
        });
    }

    public function test_cp_confirm_payment_voids_an_open_online_intent()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // Customer opened the pay page but paid in person: confirming must void the open intent to prevent a duplicate charge.
        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_open_online',
            'payment_gateway' => 'fake',
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200)
            ->assertJson(['status' => 'confirmed']);

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_open_online', $gateway->cancelledIntents[0]['payment_id']);
    }

    public function test_cp_confirm_payment_dispatches_the_confirmation_before_gateway_reconciliation()
    {
        Event::fake([ReservationConfirmedEvent::class]);
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // The confirmation event must have fired before the first gateway call so a provider hang in settlePaidOutOfBand() cannot strand the booking.
        // Recorded, not asserted, inside the hook: settle swallows Throwables.
        $confirmationFiredBeforeSettle = null;
        $gateway->onRetrievePaymentIntent = function () use (&$confirmationFiredBeforeSettle) {
            $confirmationFiredBeforeSettle ??= Event::dispatched(ReservationConfirmedEvent::class)->isNotEmpty();
        };

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_confirm_ordering',
            'payment_gateway' => 'fake',
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200)
            ->assertJson(['status' => 'confirmed']);

        $this->assertTrue($confirmationFiredBeforeSettle);
        Event::assertDispatched(ReservationConfirmedEvent::class, fn ($event) => $event->via === ReservationConfirmedEvent::VIA_CP);
    }

    public function test_cp_confirm_payment_reconciles_the_gateway_even_when_a_confirmation_listener_throws()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // A throwing synchronous ReservationConfirmed listener must not 500 the endpoint or skip gateway reconciliation.
        Event::listen(ReservationConfirmedEvent::class, function (): void {
            throw new \RuntimeException('listener boom');
        });

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_listener_boom',
            'payment_gateway' => 'fake',
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200)
            ->assertJson(['status' => 'confirmed']);

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_listener_boom', $gateway->cancelledIntents[0]['payment_id']);
    }

    public function test_cp_confirm_payment_skips_the_gateway_when_no_intent_exists()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => '',
            'payment_gateway' => 'offline',
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertCount(0, $gateway->cancelledIntents);
    }

    public function test_confirming_an_online_gateway_out_of_band_makes_the_reservation_refundable()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        $gateway->refundCalls = [];

        // Unpaid intent + out-of-band confirm: the dead intent must be voided AND its id dropped so a later refund skips the gateway.
        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_unpaid_online',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('', $reservation->payment_id);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_unpaid_online', $gateway->cancelledIntents[0]['payment_id']);

        // The intent never captured anything, so no duplicate-payment warning goes out.
        Mail::assertNotSent(OrphanedPaymentNotification::class);

        // The reservation now refunds through the CP without hitting the gateway (nothing to refund).
        $changed = app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertTrue($changed);
        $this->assertSame('refunded', $reservation->fresh()->status);
        $this->assertCount(0, $gateway->refundCalls);
    }

    public function test_confirming_online_out_of_band_keeps_a_captured_charge_reference()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        $gateway->retrievedIntentStatus = 'succeeded';

        // The intent already captured real money: the charge reference must be kept refundable, never dropped.
        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_captured',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('pi_captured', $reservation->payment_id);
        $this->assertCount(0, $gateway->cancelledIntents);

        // The succeeded webhook no-ops on CONFIRMED, so this notification is the only duplicate-payment signal.
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_DUPLICATE
                && $mail->paymentIntentId === 'pi_captured';
        });
    }

    public function test_confirming_online_out_of_band_notifies_for_every_capturing_intent_status()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');

        // 'processing' and 'requires_capture' also mean money is moving — the duplicate notification must cover them.
        foreach (['processing', 'requires_capture'] as $status) {
            $gateway->retrievedIntentStatus = $status;

            $reservation = $this->awaitingPaymentReservation([
                'payment' => '50.00',
                'payment_id' => 'pi_'.$status,
                'payment_gateway' => 'fake',
                'affects_availability' => false,
            ]);

            $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
                ->assertStatus(200);

            $this->assertSame('pi_'.$status, $reservation->fresh()->payment_id);
            Mail::assertSent(OrphanedPaymentNotification::class, fn ($mail) => $mail->paymentIntentId === 'pi_'.$status
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_DUPLICATE);
        }
    }

    public function test_confirming_online_out_of_band_notifies_when_the_intent_captures_during_the_void()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        // The customer pays inside the void window: the void is rejected (swallowed, like Stripe) and the re-read reports 'succeeded'.
        $gateway->cancelSucceeds = false;
        $gateway->onRetrievePaymentIntent = function () use ($gateway) {
            $gateway->retrievedIntentStatus = 'succeeded';
        };

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_raced_capture',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        // The charge reference survives so the captured money stays refundable through the gateway.
        $this->assertSame('pi_raced_capture', $reservation->payment_id);
        $this->assertCount(1, $gateway->cancelledIntents);

        // Must be the duplicate notification, not the generic could-not-verify log warning.
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_DUPLICATE
                && $mail->paymentIntentId === 'pi_raced_capture';
        });
    }

    public function test_confirming_online_out_of_band_keeps_the_reference_when_the_cancel_fails()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        $gateway->refundCalls = [];
        // The provider cancel fails transiently (swallowed, like Stripe): the intent stays live, so the reference must be kept.
        $gateway->cancelSucceeds = false;

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_unpaid_online',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        // Cancel was attempted but could not be verified, so the reference survives.
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_unpaid_online', $reservation->payment_id);

        // The retained intent is still payable and the webhook no-ops on CONFIRMED, so admins must be notified now.
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_STILL_PAYABLE
                && $mail->paymentIntentId === 'pi_unpaid_online';
        });

        // A later refund routes through the gateway with the real id instead of being treated as a no-charge settlement.
        app(ReservationRefundProcessor::class)->refund($reservation);

        $this->assertSame('refunded', $reservation->fresh()->status);
        $this->assertCount(1, $gateway->refundCalls);
    }

    public function test_confirming_online_out_of_band_notifies_when_the_intent_cannot_be_verified()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        // The pre-void verification read fails transiently: the intent's state is unknown.
        $gateway->onRetrievePaymentIntent = function (): void {
            throw new \RuntimeException('gateway unreachable');
        };

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_unverifiable',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        // Nothing is voided or cleared on an unverifiable intent — the reference is the only handle on a possibly-live charge.
        $this->assertSame('pi_unverifiable', $reservation->payment_id);
        $this->assertCount(0, $gateway->cancelledIntents);

        // The webhook no-ops on CONFIRMED, so admins must be notified of the possible silent duplicate.
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_UNVERIFIED
                && $mail->paymentIntentId === 'pi_unverifiable';
        });
    }

    public function test_confirming_online_out_of_band_notifies_when_the_void_verification_fails()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        // The void is attempted but the verification re-read fails — the void's outcome is unknown.
        $retrieveCalls = 0;
        $gateway->onRetrievePaymentIntent = function () use (&$retrieveCalls): void {
            if (++$retrieveCalls === 2) {
                throw new \RuntimeException('gateway unreachable');
            }
        };

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_void_unverified',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        // The void was attempted but never verified, so the reference survives for reconciliation.
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_void_unverified', $reservation->payment_id);

        // Same silent-duplicate exposure as the still-payable branch: admins must be notified.
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_UNVERIFIED
                && $mail->paymentIntentId === 'pi_void_unverified';
        });
    }

    public function test_confirming_out_of_band_with_a_removed_gateway_keeps_the_reference_and_notifies()
    {
        Mail::fake();
        $this->signInAdmin();

        // The gateway was removed from config after the intent was minted: nothing can be
        // voided or verified, and its webhook route is gone — admins must be told.
        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_removed_gateway',
            'payment_gateway' => 'gone',
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('pi_removed_gateway', $reservation->payment_id);

        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_UNVERIFIED
                && $mail->paymentIntentId === 'pi_removed_gateway';
        });
    }

    public function test_customer_cannot_self_refund_an_online_out_of_band_confirmation()
    {
        Mail::fake();
        Config::set('resrv-config.enable_customer_cancellations', true);
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // Confirmed in person before any intent existed (payment_id stays empty), inside a free-cancellation window.
        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => '',
            'payment_gateway' => 'fake',
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 5,
            'date_start' => now()->addDays(20)->startOfDay(),
            'date_end' => now()->addDays(22)->startOfDay(),
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('', $reservation->payment_id);

        // Money was collected out of band: a self-service refund would land REFUNDED without money moving, so it must not be self-refundable.
        $this->assertTrue($reservation->confirmedWithoutGatewayCharge());
        $this->assertFalse($reservation->supportsAutomaticRefund());
        $this->assertFalse($reservation->canCancelWithRefund());
        $this->assertFalse($reservation->canCancelWithoutRefund());
        $this->assertFalse($reservation->canBeCancelledByCustomer());
    }

    public function test_cancelling_an_unpaid_hold_clears_the_voided_intent_reference()
    {
        Mail::fake();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // Unpaid intent, never paid: once the void is verified the reference must be dropped or payment_id readers report money never collected.
        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_unpaid_hold',
            'payment_gateway' => 'fake',
        ]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation, cancelOpenIntent: true);

        $reservation->refresh();
        $this->assertSame('cancelled', $reservation->status);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('', $reservation->payment_id);
        $this->assertFalse($reservation->hasGatewayPayment());
        $this->assertTrue($reservation->amountPaidOnline()->isZero());
        $this->assertFalse($reservation->payment_unresolved);
    }

    public function test_cancelling_an_unpaid_hold_keeps_the_reference_when_the_void_cannot_be_verified()
    {
        Mail::fake();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        // The provider cancel fails transiently (swallowed, like Stripe): the reference survives as the only handle on the live intent.
        $gateway->cancelSucceeds = false;

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_unpaid_hold',
            'payment_gateway' => 'fake',
        ]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation, cancelOpenIntent: true);

        $reservation->refresh();
        $this->assertSame('cancelled', $reservation->status);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_unpaid_hold', $reservation->payment_id);
        // The kept reference is a reconciliation handle, not collected money — reporting must skip it.
        $this->assertTrue($reservation->payment_unresolved);
    }

    public function test_cancelling_an_unpaid_hold_with_a_removed_gateway_marks_the_reference_unresolved()
    {
        Mail::fake();

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_gone',
            'payment_gateway' => 'gone',
        ]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($reservation, cancelOpenIntent: true);

        $reservation->refresh();
        $this->assertSame('cancelled', $reservation->status);
        $this->assertSame('pi_gone', $reservation->payment_id);
        $this->assertTrue($reservation->payment_unresolved);
    }

    public function test_cancel_without_refund_voids_the_freshly_written_intent_not_a_stale_snapshot()
    {
        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => '',
            'payment_gateway' => 'fake',
        ]);

        // A model hydrated before the pay page recorded an intent.
        $stale = Reservation::find($reservation->id);

        // The pay page writes an intent after that model loaded; the void must use the locked row's value, not the stale model's.
        Reservation::where('id', $reservation->id)->update(['payment_id' => 'pi_written_after_load']);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund($stale, cancelOpenIntent: true);

        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame('pi_written_after_load', $gateway->cancelledIntents[0]['payment_id']);
    }

    public function test_cp_confirm_payment_rejects_non_awaiting_reservations()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => 'pending',
        ]);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(422);

        $this->assertEquals('pending', $reservation->fresh()->status);
    }

    public function test_cp_confirm_payment_double_submit_returns_422()
    {
        Mail::fake();
        $this->signInAdmin();

        $reservation = $this->awaitingPaymentReservation();

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        // A second submit is deliberately a 422, not a no-op, so the UI surfaces the state change.
        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(422);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
    }

    public function test_cp_cancel_awaiting_restores_stock_and_emails_the_customer()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->awaitingPaymentReservationWithStock(available: 2, attributes: [
            'affects_availability' => true,
            'payment_id' => 'pi_awaiting_cancel',
            'payment_gateway' => 'fake',
        ]);

        // Simulate the decrement the creation flow performs when the flag is on.
        (new Availability)->decrementAvailability(
            date_start: $reservation->date_start,
            date_end: $reservation->date_end,
            quantity: $reservation->quantity,
            statamic_id: $reservation->item_id,
            reservationId: $reservation->id,
            rateId: $reservation->rate_id,
        );
        $this->assertEquals(1, $this->availableOn($reservation->item_id, today()));

        $response = $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertJson([
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertEquals(2, $this->availableOn($reservation->item_id, today()));
        Mail::assertSent(ReservationCancelledCustomer::class, fn ($mail) => $mail->hasTo($reservation->customer->email));

        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertEquals('pi_awaiting_cancel', $gateway->cancelledIntents[0]['payment_id']);
    }

    public function test_cp_cancel_awaiting_does_not_restore_stock_when_the_flag_is_off()
    {
        Mail::fake();
        $this->signInAdmin();

        $reservation = $this->awaitingPaymentReservationWithStock(available: 2, attributes: [
            'affects_availability' => false,
        ]);

        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertStatus(200);

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertEquals(2, $this->availableOn($reservation->item_id, today()));
    }

    public function test_cp_cancel_awaiting_skips_the_gateway_when_no_intent_exists()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => '',
            'payment_gateway' => 'fake',
        ]);

        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertStatus(200);

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertCount(0, $gateway->cancelledIntents);
    }

    public function test_cp_cancel_awaiting_rejects_non_awaiting_reservations()
    {
        Mail::fake();
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ]);

        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertStatus(422);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_cp_cancel_awaiting_rechecks_the_origin_status_under_the_row_lock()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $reservation = $this->awaitingPaymentReservation([
            'payment_id' => 'pi_race_paid',
            'payment_gateway' => 'fake',
        ]);

        // Regression: a webhook confirm between hydrate and transitionTo()'s row lock must trip the in-transaction origin re-check.
        // The retrieved hook flips the row to CONFIRMED at DB level to simulate the cross-process race.
        Reservation::retrieved(function (Reservation $model) use ($reservation) {
            if ((int) $model->id === $reservation->id && $model->status === ReservationStatus::AWAITING_PAYMENT->value) {
                DB::table('resrv_reservations')
                    ->where('id', $reservation->id)
                    ->update(['status' => ReservationStatus::CONFIRMED->value]);
            }
        });

        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertStatus(422);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Mail::assertNotSent(ReservationCancelledCustomer::class);
        $this->assertCount(0, $gateway->cancelledIntents);
    }

    public function test_cp_confirm_payment_that_loses_the_race_to_a_webhook_still_detects_the_duplicate()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];
        $gateway->retrievedIntentStatus = 'succeeded';

        $reservation = $this->awaitingPaymentReservation([
            'payment' => '50.00',
            'payment_id' => 'pi_webhook_won',
            'payment_gateway' => 'fake',
            'affects_availability' => false,
        ]);

        // The webhook wins the race (same-state no-op transition), but the out-of-band claim must still trigger duplicate reconciliation.
        // The retrieved hook flips the row to CONFIRMED at DB level to simulate the cross-process race.
        Reservation::retrieved(function (Reservation $model) use ($reservation) {
            if ((int) $model->id === $reservation->id && $model->status === ReservationStatus::AWAITING_PAYMENT->value) {
                DB::table('resrv_reservations')
                    ->where('id', $reservation->id)
                    ->update(['status' => ReservationStatus::CONFIRMED->value]);
            }
        });

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertStatus(200);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);

        // The captured charge reference is kept refundable, never voided, and the admins are told.
        $this->assertSame('pi_webhook_won', $reservation->payment_id);
        $this->assertCount(0, $gateway->cancelledIntents);
        Mail::assertSent(OrphanedPaymentNotification::class, function ($mail) {
            return $mail->paymentIntentId === 'pi_webhook_won'
                && $mail->context === OrphanedPaymentNotification::CONTEXT_OUT_OF_BAND_DUPLICATE;
        });
    }

    public function test_both_endpoints_are_forbidden_without_the_use_resrv_permission()
    {
        $this->withExceptionHandling();

        $role = Role::make('role_'.Str::random(8))->addPermission(['access cp'])->save();
        $user = User::make()
            ->id('user-'.Str::random(8))
            ->email(Str::random(8).'@test.com')
            ->assignRole($role);
        $this->actingAs($user);

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => 1]))->assertForbidden();
        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => 1]))->assertForbidden();
    }
}

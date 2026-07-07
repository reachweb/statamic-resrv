<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
        // Coordination point 1 with plan 008: the canceled handler only expires PENDING rows.
        // An admin-created hold must survive its intent being cancelled (e.g. by Stripe's TTL).
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

        $reservation = $this->awaitingPaymentReservation([
            'payment_gateway' => 'offline',
        ]);

        $response = $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertJson([
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Mail::assertSent(ReservationConfirmedMail::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
    }

    public function test_cp_confirm_payment_voids_an_open_online_intent()
    {
        Mail::fake();
        $this->signInAdmin();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        // The customer opened the online pay page (an intent was created) but paid in person;
        // the admin marks the reservation paid. The open intent must be voided so completing it
        // later can't produce a duplicate charge the succeeded webhook would silently swallow.
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

        // Online gateway ('fake'), customer opened the pay link (an unpaid intent id sits on the
        // row), admin marks it paid in person. Confirming must void the dead intent AND drop its id
        // so a later refund is not routed to the gateway with an empty/voided intent — which real
        // Stripe rejects, stranding the booking as un-refundable.
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

        // A webhook confirm is racing this manual one: the intent already captured real money. The
        // charge reference must be kept so it stays refundable through the gateway, never dropped
        // (which would strand the captured charge the succeeded webhook then swallows).
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

        // The pay page writes a live intent to the row after that model was loaded but before
        // the cancellation acquires its lock. cancelWithoutRefund must void the intent recorded
        // on the locked, committed row — not the empty value on the stale in-memory model.
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

        // A second submit finds the reservation CONFIRMED — deliberately a 422, not a no-op,
        // so the UI surfaces that the state moved under the admin.
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

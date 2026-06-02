<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Stripe\Stripe;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeWebhookTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));

        Config::set('resrv-config.stripe_webhook_secret', 'whsec_test');
        Config::set('resrv-config.stripe_secret_key', 'sk_test');
    }

    private function succeededWebhookRequest(string $paymentIntentId): Request
    {
        return Request::create('/resrv/api/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $paymentIntentId]],
        ]));
    }

    /**
     * Builds a request with a valid HMAC for the given secret (fixed timestamp; empty-secret guard
     * fires before the timestamp tolerance check).
     */
    private function forgedSucceededWebhookRequest(string $paymentIntentId, string $secret): Request
    {
        return $this->signedSucceededWebhookRequest($paymentIntentId, $secret, 1700000000);
    }

    /**
     * Builds a request signed with a real HMAC, mirroring Stripe's `t=...,v1=...` header.
     * Pass a stale timestamp to exercise the replay-protection window.
     */
    private function signedSucceededWebhookRequest(string $paymentIntentId, string $secret, int $timestamp): Request
    {
        return $this->signedWebhookRequest('payment_intent.succeeded', $paymentIntentId, $secret, $timestamp);
    }

    private function signedSucceededWebhookRequestWithAmount(string $paymentIntentId, string $secret, int $timestamp, int $amountReceived): Request
    {
        return $this->signedWebhookRequest('payment_intent.succeeded', $paymentIntentId, $secret, $timestamp, ['amount_received' => $amountReceived]);
    }

    private function signedFailedWebhookRequest(string $paymentIntentId, string $secret, int $timestamp): Request
    {
        return $this->signedWebhookRequest('payment_intent.payment_failed', $paymentIntentId, $secret, $timestamp);
    }

    private function signedCanceledWebhookRequest(string $paymentIntentId, string $secret, int $timestamp): Request
    {
        return $this->signedWebhookRequest('payment_intent.canceled', $paymentIntentId, $secret, $timestamp);
    }

    /**
     * Builds a request signed with a real HMAC for the given Stripe event type, mirroring
     * Stripe's `t=...,v1=...` header. The signature covers the raw payload, so it stays valid
     * for any event type.
     */
    private function signedWebhookRequest(string $type, string $paymentIntentId, string $secret, int $timestamp, array $extraObjectData = [], ?string $eventId = null): Request
    {
        $event = [
            'type' => $type,
            'data' => ['object' => array_merge(['id' => $paymentIntentId], $extraObjectData)],
        ];

        if ($eventId !== null) {
            $event['id'] = $eventId;
        }

        $payload = json_encode($event);

        $signature = sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', "{$timestamp}.{$payload}", $secret)
        );

        // Pass the signature as an HTTP_* server var so it lands in the request header bag,
        // matching how the gateway now reads it ($request->header('Stripe-Signature')).
        return Request::create('/resrv/api/webhook', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }

    /**
     * FakePaymentGateway mirrors the real Stripe gateway's terminal-state guards and orphan
     * notification path, but without Stripe signature verification — letting us exercise the
     * webhook-on-terminal logic in tests without mocking the Stripe SDK.
     */
    private function fakeGatewaySuccessRequest(int $reservationId): Request
    {
        return Request::create('/resrv/api/webhook', 'POST', [
            'reservation_id' => $reservationId,
            'status' => 'success',
        ]);
    }

    private function fakeGatewayFailRequest(int $reservationId): Request
    {
        return Request::create('/resrv/api/webhook', 'POST', [
            'reservation_id' => $reservationId,
            'status' => 'fail',
        ]);
    }

    public function test_verify_payment_short_circuits_for_already_confirmed_reservation()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_test_already_confirmed',
            'payment_gateway' => 'stripe',
        ]);

        // A correctly-signed duplicate succeeded webhook for an already-CONFIRMED reservation is a
        // no-op (idempotent): 200 with no re-confirmation.
        $request = $this->signedSucceededWebhookRequest('pi_test_already_confirmed', 'whsec_test', time());

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($request);

        $this->assertNotNull($response, 'verifyPayment should return a response for a CONFIRMED reservation.');
        $this->assertEquals(200, $response->getStatusCode());

        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_verify_payment_verifies_signature_before_confirmed_short_circuit()
    {
        // L1/L3: an unsigned webhook (no Stripe-Signature header) must be rejected (403) before
        // reaching the already-confirmed short-circuit, which would otherwise leak reservation
        // status to an unauthenticated caller.
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_test_unsigned_confirmed',
            'payment_gateway' => 'stripe',
        ]);

        $gateway = app(StripePaymentGateway::class);

        try {
            $gateway->verifyPayment($this->succeededWebhookRequest('pi_test_unsigned_confirmed'));
            $this->fail('verifyPayment should reject an unsigned webhook before the confirmed short-circuit.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_fake_gateway_rejects_succeeded_webhook_for_expired_reservation_and_sends_orphan_notification()
    {
        Mail::fake();
        Event::fake([ReservationConfirmed::class]);
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
            'payment_id' => 'pi_test_orphan',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $response = $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('expired', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($m) => $m->hasTo('admin@example.com'));
    }

    public function test_fake_gateway_rejects_succeeded_webhook_for_refunded_reservation()
    {
        Mail::fake();
        Event::fake([ReservationConfirmed::class]);
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'refunded',
            'payment_id' => 'pi_test_refunded_orphan',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $response = $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('refunded', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class);
    }

    public function test_fake_gateway_rejects_succeeded_webhook_for_partner_reservation()
    {
        Mail::fake();
        Event::fake([ReservationConfirmed::class]);
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'partner',
            'payment_id' => 'pi_test_partner_orphan',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $response = $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('partner', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class);
    }

    public function test_stripe_orphan_notification_includes_the_verified_event_id()
    {
        // L6: the orphan email's most useful reconciliation handle is the Stripe event id; the real
        // gateway must pass it through so the template's "Event id" line renders.
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
            'payment_id' => 'pi_test_event_id',
            'payment_gateway' => 'stripe',
        ]);

        $request = $this->signedWebhookRequest('payment_intent.succeeded', 'pi_test_event_id', 'whsec_test', time(), [], 'evt_test_orphan_123');

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($mail) => $mail->stripeEventId === 'evt_test_orphan_123');
    }

    public function test_duplicate_orphan_webhook_only_notifies_admins_once()
    {
        // L5: Stripe redelivers webhooks for up to ~3 days; an orphan charge against a terminal
        // reservation must not re-email admins on every retry.
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
            'payment_id' => 'pi_test_orphan_dedup',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));
        $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        // One notification despite two webhook deliveries for the same orphaned charge.
        Mail::assertSent(OrphanedPaymentNotification::class, 1);
        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    public function test_orphan_notification_sends_one_message_per_admin_recipient()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin1@example.com,admin2@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
            'payment_id' => 'pi_test_multi_admin',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        Mail::assertSent(OrphanedPaymentNotification::class, 2);
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($m) => $m->hasTo('admin1@example.com') && ! $m->hasTo('admin2@example.com'));
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($m) => $m->hasTo('admin2@example.com') && ! $m->hasTo('admin1@example.com'));
    }

    public function test_fake_gateway_succeeded_webhook_for_pending_reservation_transitions_and_dispatches()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_pending_ok',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $response = $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($e) => $e->reservation->id === $reservation->id);
    }

    public function test_fake_gateway_duplicate_succeeded_webhook_is_idempotent()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_duplicate',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));
        $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));

        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatchedTimes(ReservationConfirmed::class, 1);
    }

    public function test_verify_payment_rejects_forged_webhook_when_secret_is_not_configured()
    {
        Event::fake([ReservationConfirmed::class]);

        // Empty string is the shipped default for RESRV_STRIPE_WEBHOOK_SECRET.
        Config::set('resrv-config.stripe_webhook_secret', '');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_empty_secret',
            'payment_gateway' => 'stripe',
        ]);

        // An attacker can compute a valid v1 HMAC when the secret is empty; the guard must reject this.
        $request = $this->forgedSucceededWebhookRequest('pi_test_empty_secret', '');

        $gateway = app(StripePaymentGateway::class);

        try {
            $gateway->verifyPayment($request);
            $this->fail('verifyPayment should abort when the Stripe webhook secret is not configured.');
        } catch (HttpException $e) {
            $this->assertEquals(500, $e->getStatusCode());
        }

        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_verify_payment_rejects_replayed_webhook_outside_timestamp_tolerance()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_replay',
            'payment_gateway' => 'stripe',
        ]);

        // Correctly signed but timestamped 600s ago — outside Stripe's 300s replay-protection window.
        $request = $this->signedSucceededWebhookRequest('pi_test_replay', 'whsec_test', time() - 600);

        $gateway = app(StripePaymentGateway::class);

        try {
            $gateway->verifyPayment($request);
            $this->fail('verifyPayment should reject a webhook whose timestamp is outside the tolerance window.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_verify_payment_accepts_freshly_signed_webhook_within_tolerance()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_fresh',
            'payment_gateway' => 'stripe',
        ]);

        // Freshly-signed event within the tolerance window — happy path must still confirm.
        $request = $this->signedSucceededWebhookRequest('pi_test_fresh', 'whsec_test', time());

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($e) => $e->reservation->id === $reservation->id);
    }

    public function test_verify_payment_does_not_confirm_when_charged_amount_differs_from_reservation_total()
    {
        // L4: defense-in-depth — a signed succeeded webhook whose amount_received does not match what
        // the reservation owes must not confirm; it's an orphaned charge for manual reconciliation.
        Mail::fake();
        Event::fake([ReservationConfirmed::class]);
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment' => 50,
            'payment_id' => 'pi_test_amount_mismatch',
            'payment_gateway' => 'stripe',
        ]);

        // Reservation owes 50.00 (5000 minor units); report a different charged amount.
        $request = $this->signedSucceededWebhookRequestWithAmount('pi_test_amount_mismatch', 'whsec_test', time(), 9900);

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
        Mail::assertSent(OrphanedPaymentNotification::class, fn ($m) => $m->hasTo('admin@example.com'));
    }

    public function test_verify_payment_confirms_when_charged_amount_matches_reservation_total()
    {
        // L4: the amount guard must not block the happy path — a matching amount_received confirms.
        Mail::fake();
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment' => 50,
            'payment_id' => 'pi_test_amount_match',
            'payment_gateway' => 'stripe',
        ]);

        // 50.00 => 5000 minor units, matching the reservation total.
        $request = $this->signedSucceededWebhookRequestWithAmount('pi_test_amount_match', 'whsec_test', time(), 5000);

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($e) => $e->reservation->id === $reservation->id);
        Mail::assertNotSent(OrphanedPaymentNotification::class);
    }

    public function test_verify_payment_does_not_mutate_the_global_stripe_api_key()
    {
        // L7: the gateway resolves its key via per-call instance clients, so verifyPayment must not
        // write the process-global Stripe key — under a persistent worker (Octane) with multisite
        // per-collection keys a leaked global would target the wrong Stripe account.
        Event::fake([ReservationConfirmed::class]);

        $original = Stripe::getApiKey();
        Stripe::setApiKey('sk_sentinel');

        try {
            $reservation = Reservation::factory()->withCustomer()->create([
                'item_id' => $this->entries->first()->id(),
                'status' => 'pending',
                'payment_id' => 'pi_test_no_global_key',
                'payment_gateway' => 'stripe',
            ]);

            $request = $this->signedSucceededWebhookRequest('pi_test_no_global_key', 'whsec_test', time());

            app(StripePaymentGateway::class)->verifyPayment($request);

            // Untouched — the gateway never calls Stripe::setApiKey on the global SDK singleton.
            $this->assertEquals('sk_sentinel', Stripe::getApiKey());
            $this->assertEquals('confirmed', $reservation->fresh()->status);
        } finally {
            Stripe::setApiKey($original);
        }
    }

    public function test_stripe_payment_failed_webhook_leaves_reservation_pending_and_does_not_release_hold()
    {
        // A failed attempt is retryable: stay PENDING. No ReservationCancelled/ReservationExpired
        // means the hold is not released (IncreaseAvailability only listens to those events).
        Event::fake([ReservationCancelled::class, ReservationConfirmed::class, ReservationExpired::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_failed',
            'payment_gateway' => 'stripe',
        ]);

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($this->signedFailedWebhookRequest('pi_test_failed', 'whsec_test', time()));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationCancelled::class);
        Event::assertNotDispatched(ReservationExpired::class);
    }

    public function test_stripe_succeeded_webhook_after_payment_failed_confirms_reservation()
    {
        // Retry path: a declined attempt then a success on the same intent must confirm.
        Event::fake([ReservationCancelled::class, ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_retry',
            'payment_gateway' => 'stripe',
        ]);

        $gateway = app(StripePaymentGateway::class);

        // First attempt fails — stays PENDING.
        $failed = $gateway->verifyPayment($this->signedFailedWebhookRequest('pi_test_retry', 'whsec_test', time()));
        $this->assertEquals(200, $failed->getStatusCode());
        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationCancelled::class);

        // Retry on the same intent succeeds.
        $succeeded = $gateway->verifyPayment($this->signedSucceededWebhookRequest('pi_test_retry', 'whsec_test', time()));
        $this->assertEquals(200, $succeeded->getStatusCode());
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($e) => $e->reservation->id === $reservation->id);
    }

    public function test_stripe_canceled_webhook_expires_pending_reservation_without_refunding()
    {
        // A genuinely-canceled intent is dead: expire (not refund) the reservation.
        Event::fake([ReservationExpired::class, ReservationCancelled::class, ReservationConfirmed::class]);

        // Stub the manager so expire()'s remote cancel never reaches the real Stripe SDK.
        $stubGateway = Mockery::mock(StripePaymentGateway::class);
        $stubGateway->shouldReceive('cancelPaymentIntent')->andReturnNull();
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('gateway')->andReturn($stubGateway);
        $this->instance(PaymentGatewayManager::class, $manager);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_canceled',
            'payment_gateway' => 'stripe',
        ]);

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($this->signedCanceledWebhookRequest('pi_test_canceled', 'whsec_test', time()));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('expired', $reservation->fresh()->status);
        Event::assertDispatched(ReservationExpired::class, fn ($e) => $e->reservation->id === $reservation->id);
        Event::assertNotDispatched(ReservationCancelled::class);
    }

    public function test_fake_gateway_failed_payment_leaves_reservation_pending_and_does_not_release_hold()
    {
        Event::fake([ReservationCancelled::class, ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_fake_fail',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);
        $response = $gateway->verifyPayment($this->fakeGatewayFailRequest($reservation->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationCancelled::class);
    }

    public function test_fake_gateway_succeeded_webhook_after_failed_payment_confirms_reservation()
    {
        Event::fake([ReservationCancelled::class, ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
            'payment_id' => 'pi_test_fake_retry',
            'payment_gateway' => 'fake',
        ]);

        $gateway = app(FakePaymentGateway::class);

        $failed = $gateway->verifyPayment($this->fakeGatewayFailRequest($reservation->id));
        $this->assertEquals(200, $failed->getStatusCode());
        $this->assertEquals('pending', $reservation->fresh()->status);
        Event::assertNotDispatched(ReservationCancelled::class);

        $succeeded = $gateway->verifyPayment($this->fakeGatewaySuccessRequest($reservation->id));
        $this->assertEquals(200, $succeeded->getStatusCode());
        $this->assertEquals('confirmed', $reservation->fresh()->status);
        Event::assertDispatched(ReservationConfirmed::class, fn ($e) => $e->reservation->id === $reservation->id);
    }
}

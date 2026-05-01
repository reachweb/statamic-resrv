<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

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

    public function test_verify_payment_short_circuits_for_already_confirmed_reservation()
    {
        Event::fake([ReservationConfirmed::class]);

        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_test_already_confirmed',
            'payment_gateway' => 'stripe',
        ]);

        $gateway = app(StripePaymentGateway::class);
        $response = $gateway->verifyPayment($this->succeededWebhookRequest('pi_test_already_confirmed'));

        $this->assertNotNull($response, 'verifyPayment should return a response for a CONFIRMED reservation.');
        $this->assertEquals(200, $response->getStatusCode());

        Event::assertNotDispatched(ReservationConfirmed::class);
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
}

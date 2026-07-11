<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Events\BuildingReservationEmail;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Mail\ReservationPaymentRequest;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;

class PaymentRequestEmailTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Online',
                'surcharge' => ['type' => 'percent', 'amount' => 10],
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        // findOrCreateCollection (not makeStatamicItem) so the pages collection gets the
        // resrv blueprint — entries created later must land in resrv_entries.
        $page = Entry::make()
            ->collection($this->findOrCreateCollection('pages'))
            ->slug('pay-here')
            ->data(['title' => 'Pay here']);
        $page->save();
        Config::set('resrv-config.manual_reservations_payment_entry', [$page->id()]);
    }

    private function creator(): ManualReservationCreator
    {
        return app(ManualReservationCreator::class);
    }

    private function baseInput($entry, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
            'payment_gateway' => 'fake',
            'customer' => [
                'email' => 'customer@example.com',
                'repeat_email' => 'customer@example.com',
                'first_name' => 'Test',
                'last_name' => 'Customer',
            ],
        ], $overrides);
    }

    public function test_create_with_the_toggle_on_sends_the_payment_request_and_stamps_it()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $reservation = $this->creator()->create($this->baseInput($entry));

        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->hasTo('customer@example.com'));
        $this->assertNotNull($reservation->payment_request_email_sent_at);

        // The rendered body carries the amount including the surcharge and the pay link.
        $html = (new ReservationPaymentRequest($reservation))->render();
        $this->assertStringContainsString('110.00', $html);
        $this->assertStringContainsString('ref='.$reservation->reference, $html);
        $this->assertStringContainsString('Pay now', $html);
    }

    public function test_create_with_the_toggle_off_sends_nothing_and_leaves_no_stamp()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->payment_request_email_sent_at);
    }

    public function test_offline_gateway_gets_the_no_link_variant()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_gateway' => 'offline',
        ]));

        $html = (new ReservationPaymentRequest($reservation))->render();
        $this->assertStringNotContainsString('Pay now', $html);
        $this->assertStringContainsString('as soon as your payment arrives', $html);
        $this->assertStringContainsString($reservation->reference, $html);
    }

    public function test_disabled_event_config_sends_nothing_and_leaves_no_stamp()
    {
        Mail::fake();

        Config::set('resrv-config.reservation_emails_global', [
            ['event' => 'customer_payment_request', 'enabled' => false],
        ]);

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $reservation = $this->creator()->create($this->baseInput($entry));

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->payment_request_email_sent_at);
    }

    public function test_zero_total_sends_the_confirmation_and_never_a_payment_request()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2, price: 0);

        $reservation = $this->creator()->create($this->baseInput($entry));

        $this->app->terminate();

        $this->assertSame('confirmed', $reservation->status);
        Mail::assertSent(ReservationConfirmedMail::class);
        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_sends_and_restamps()
    {
        Mail::fake();
        $this->signInAdmin();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));
        $this->assertNull($reservation->payment_request_email_sent_at);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertOk()
            ->assertJsonPath('id', $reservation->id);

        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->hasTo('customer@example.com'));
        $this->assertNotNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_rejects_confirmed_reservations_and_disabled_events()
    {
        Mail::fake();
        $this->signInAdmin();
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $confirmed = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ]);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $confirmed->id]))
            ->assertStatus(422);

        Config::set('resrv-config.reservation_emails_global', [
            ['event' => 'customer_payment_request', 'enabled' => false],
        ]);

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $awaiting = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $awaiting->id]))
            ->assertStatus(422);

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($awaiting->fresh()->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_rejects_an_online_request_when_the_payment_page_is_gone()
    {
        Mail::fake();
        $this->signInAdmin();
        $this->withExceptionHandling();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        // The payment page was unconfigured/unpublished after creation: an online payment
        // request now has no link to pay through, so the resend must refuse instead of sending
        // offline-payment wording — and it must not stamp a send that never happened.
        Config::set('resrv-config.manual_reservations_payment_entry', null);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The payment page entry is not configured or published, so an online payment request cannot be sent.');

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_rejects_when_the_recorded_gateway_is_gone()
    {
        Mail::fake();
        $this->signInAdmin();
        $this->withExceptionHandling();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        // The recorded gateway is removed from the configuration after creation. The payment
        // page still exists, so the pay link would render — but it could only error when the
        // page fails to resolve the gateway. The resend must refuse instead of emailing a
        // dead-end "Pay now" link and stamping a send.
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The payment method recorded on this reservation is no longer configured, so a payment request cannot be sent.');

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_rejects_when_the_payment_deadline_has_passed()
    {
        Mail::fake();
        $this->signInAdmin();
        $this->withExceptionHandling();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
            'hold_days' => 1,
        ]));

        // The deadline lapsed but the sweep has not cancelled the hold yet. The pay page already
        // refuses this link as expired, so resending would email an unusable "Pay now" with a
        // past deadline — the resend must refuse and leave no stamp.
        $reservation->update(['hold_expires_at' => now()->subHour()]);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The payment deadline for this reservation has passed, so a payment request cannot be sent.');

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_offline_resend_still_works_without_a_payment_page()
    {
        Mail::fake();
        $this->signInAdmin();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_gateway' => 'offline',
            'send_payment_request_email' => false,
        ]));

        Config::set('resrv-config.manual_reservations_payment_entry', null);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertOk();

        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->hasTo('customer@example.com'));
        $this->assertNotNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_online_request_without_a_pay_link_renders_contact_copy_not_transfer_wording()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        Config::set('resrv-config.manual_reservations_payment_entry', null);

        // Defense-in-depth for senders that bypass the guard: the template itself must not
        // imply a bank-transfer flow for an online reservation that simply lost its pay link.
        $html = (new ReservationPaymentRequest($reservation))->render();
        $this->assertStringNotContainsString('Pay now', $html);
        $this->assertStringNotContainsString('as soon as your payment arrives', $html);
        $this->assertStringContainsString('Please contact us to arrange the payment', $html);
    }

    public function test_building_reservation_email_hook_fires_for_the_payment_request()
    {
        Event::fake([BuildingReservationEmail::class]);

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        (new ReservationPaymentRequest($reservation))->render();

        Event::assertDispatched(BuildingReservationEmail::class, function ($event) use ($reservation) {
            return $event->reservation?->id === $reservation->id
                && $event->mailable instanceof ReservationPaymentRequest;
        });
    }

    public function test_configured_subject_override_applies()
    {
        Mail::fake();

        Config::set('resrv-config.reservation_emails_global', [
            ['event' => 'customer_payment_request', 'enabled' => true, 'subject' => 'Please pay for your booking'],
        ]);

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->creator()->create($this->baseInput($entry));

        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->subject === 'Please pay for your booking');
    }
}

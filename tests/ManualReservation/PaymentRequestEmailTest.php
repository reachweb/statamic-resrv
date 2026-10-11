<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Events\BuildingReservationEmail;
use Reach\StatamicResrv\Exceptions\ManualReservationException;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Mail\ReservationPaymentRequest;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
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

        // findOrCreateCollection so the pages collection gets the resrv blueprint — later entries must land in resrv_entries.
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

        // The surcharge explains the gap between Total and Amount to pay; a full request leaves no balance.
        $this->assertStringContainsString('Payment surcharge', $html);
        $this->assertStringNotContainsString('Remaining amount', $html);
        $this->assertStringContainsString('Hi Test,', $html);
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

        // Without the instructions setting the email says payment is arranged directly and never promises instructions.
        $this->assertStringContainsString('payment is arranged directly with us', $html);
        $this->assertStringContainsString('quoting the booking reference '.$reservation->reference, $html);
        $this->assertStringNotContainsString('How to pay', $html);
    }

    public function test_offline_instructions_setting_renders_escaped_with_line_breaks()
    {
        Mail::fake();
        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: ACME <b>Ltd</b>\r\nIBAN: GR12 3456");

        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $offline = $this->creator()->create($this->baseInput($entry, [
            'payment_gateway' => 'offline',
            'send_payment_request_email' => false,
        ]));

        $html = (new ReservationPaymentRequest($offline))->render();
        $this->assertStringContainsString('How to pay', $html);
        $this->assertStringContainsString("Bank: ACME &lt;b&gt;Ltd&lt;/b&gt;<br>\nIBAN: GR12 3456", $html);
        $this->assertStringNotContainsString('<b>Ltd</b>', $html);
        $this->assertStringContainsString('Please include the booking reference '.$offline->reference.' with your payment.', $html);
        $this->assertStringNotContainsString('payment is arranged directly with us', $html);

        // Online requests carry the pay link, never the offline instructions.
        $online = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        $html = (new ReservationPaymentRequest($online))->render();
        $this->assertStringNotContainsString('IBAN: GR12 3456', $html);
        $this->assertStringContainsString('Pay now', $html);
    }

    public function test_offline_instructions_stay_literal_text_in_both_parts()
    {
        Config::set('resrv-config.manual_reservations_offline_instructions', "1. Transfer to *ACME* [here](https://x.test)\n- IBAN: GR12 & co\n\n# Quote your reference");

        $reservation = $this->offlineReservationForRender();

        $mail = new ReservationPaymentRequest($reservation);
        $html = $mail->render();

        // Same literal text as the pay page: no list, emphasis, link or heading parsing.
        $this->assertStringContainsString('1. Transfer to *ACME* [here](https://x.test)<br>', $html);
        $this->assertStringContainsString('- IBAN: GR12 &amp; co<br>', $html);
        $this->assertStringContainsString('# Quote your reference', $html);
        $this->assertStringNotContainsString('<em>ACME</em>', $html);
        $this->assertStringNotContainsString('href="https://x.test"', $html);
        $this->assertStringNotContainsString('<ol', $html);
        $this->assertStringNotContainsString('<h1', $html);

        // The plain-text alternative keeps one instruction per line.
        (new ReservationPaymentRequest($reservation))->assertSeeInText("1. Transfer to *ACME* [here](https://x.test)\n- IBAN: GR12 & co\n");
    }

    public function test_plain_text_part_keeps_the_instruction_lines_apart()
    {
        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: Example Bank S.A.\nIBAN: GR16 0110 1250\nPlease quote your reference.");

        (new ReservationPaymentRequest($this->offlineReservationForRender()))
            ->assertSeeInText("Bank: Example Bank S.A.\nIBAN: GR16 0110 1250\nPlease quote your reference.")
            ->assertDontSeeInText('S.A.IBAN');
    }

    public function test_the_deadline_carries_its_time_zone()
    {
        $reservation = $this->offlineReservationForRender([
            'hold_expires_at' => now()->addDay()->setTime(20, 50),
            'date_start' => now()->addDays(5)->setTime(12, 0),
            'date_end' => now()->addDays(7)->setTime(12, 0),
        ]);

        $html = $this->withoutInlineStyles((new ReservationPaymentRequest($reservation))->render());

        $this->assertStringContainsString('Please pay by: <strong>'.$reservation->hold_expires_at->format('d-m-Y H:i T').'</strong>', $html);
    }

    public function test_please_pay_by_names_the_end_of_the_arrival_day_when_it_comes_first()
    {
        $arrival = now()->addDay()->setTime(12, 0);
        $reservation = $this->offlineReservationForRender([
            'hold_expires_at' => now()->addDays(5)->setTime(9, 0),
            'date_start' => $arrival,
            'date_end' => $arrival->copy()->addDays(2),
        ]);

        $html = $this->withoutInlineStyles((new ReservationPaymentRequest($reservation))->render());

        // Payment closes when the arrival day ends, so the later hold deadline must not be promised.
        $this->assertStringContainsString('Please pay by the end of your arrival day: <strong>'.$arrival->copy()->endOfDay()->format('d-m-Y H:i T').'</strong>', $html);
        $this->assertStringNotContainsString($reservation->hold_expires_at->format('d-m-Y H:i'), $html);
    }

    public function test_a_first_request_introduces_the_reservation()
    {
        $reservation = $this->offlineReservationForRender([
            'hold_expires_at' => now()->addDays(2)->setTime(18, 0),
            'payment_request_email_sent_at' => null,
        ]);

        $mail = new ReservationPaymentRequest($reservation);
        $html = $mail->render();

        $this->assertFalse($mail->reminder);
        $this->assertStringContainsString('We have created a reservation for you.', $html);
        $this->assertStringNotContainsString('for your reservation again', $html);
    }

    public function test_a_repeat_request_reads_as_a_reminder_with_the_current_deadline()
    {
        $reservation = $this->offlineReservationForRender([
            'payment_gateway' => 'fake',
            'hold_expires_at' => now()->addDays(2)->setTime(18, 0),
            'payment_request_email_sent_at' => now()->subDay(),
        ]);

        $html = (new ReservationPaymentRequest($reservation))->render();

        // A plain resend: the deadline did not change, so the panel's "Please pay by" carries it.
        $this->assertStringContainsString('Here is the payment link for your reservation again.', $html);
        $this->assertStringNotContainsString('Your payment deadline is now', $html);
        $this->assertStringContainsString($reservation->hold_expires_at->format('d-m-Y H:i T'), $html);
        $this->assertStringNotContainsString('We have created a reservation for you.', $html);

        $html = (new ReservationPaymentRequest($reservation, deadlineChanged: true))->render();
        $this->assertStringContainsString('Your payment deadline is now '.$reservation->hold_expires_at->format('d-m-Y H:i T').'.', $html);

        // Offline reminders have no link to repeat; without a deadline they ask for the payment.
        $reservation->update(['payment_gateway' => 'offline', 'hold_expires_at' => null]);

        $html = (new ReservationPaymentRequest($reservation->fresh()))->render();

        $this->assertStringContainsString('Here are the payment details for your reservation again.', $html);
        $this->assertStringContainsString('Please complete the payment to confirm it.', $html);
        $this->assertStringNotContainsString('Your payment deadline is now', $html);
    }

    public function test_an_explicit_flag_overrides_the_stamp()
    {
        $reservation = $this->offlineReservationForRender(['payment_request_email_sent_at' => now()->subDay()]);

        $html = (new ReservationPaymentRequest($reservation, reminder: false))->render();

        $this->assertStringContainsString('We have created a reservation for you.', $html);
    }

    public function test_the_resend_endpoint_sends_a_reminder_after_the_first_request()
    {
        Mail::fake();
        $this->signInAdmin();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, ['hold_days' => 2]));

        // The first send is captured before the stamp, so it never reads as a reminder.
        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->reminder === false);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertOk();

        Mail::assertSent(ReservationPaymentRequest::class, 2);
        Mail::assertSent(ReservationPaymentRequest::class, fn ($mail) => $mail->reminder === true && $mail->deadlineChanged === false);
    }

    public function test_extending_the_hold_with_a_resend_tells_the_customer_the_new_deadline()
    {
        Mail::fake();
        $this->signInAdmin();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry));

        $this->postJson(cp_route('resrv.reservation.extendHold', ['id' => $reservation->id]), [
            'hold_days' => 1,
            'resend' => true,
        ])->assertOk();

        $deadline = $reservation->fresh()->hold_expires_at;
        $this->assertNotNull($deadline);

        $reminder = Mail::sent(ReservationPaymentRequest::class)->last();
        $this->assertTrue($reminder->reminder);
        $this->assertTrue($reminder->deadlineChanged);

        $html = $this->withoutInlineStyles($reminder->render());
        $this->assertStringContainsString('Your payment deadline is now '.$deadline->format('d-m-Y H:i T').'.', $html);
        $this->assertStringContainsString('Please pay by: <strong>'.$deadline->format('d-m-Y H:i T').'</strong>', $html);
        $this->assertStringNotContainsString('We have created a reservation for you.', $html);
    }

    private function offlineReservationForRender(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->create(array_merge([
            'status' => 'awaiting_payment',
            'item_id' => $item->id(),
            'customer_id' => Customer::factory()->create()->id,
            'payment' => 30,
            'payment_surcharge' => 0,
            'total' => 30,
            'payment_gateway' => 'offline',
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
        ], $attributes));
    }

    private function withoutInlineStyles(string $html): string
    {
        return preg_replace('/ style="[^"]*"/', '', $html);
    }

    public function test_the_email_lists_extras_options_and_the_remaining_amount()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory()->create([
            'status' => 'awaiting_payment',
            'item_id' => $item->id(),
            'customer_id' => Customer::factory()->create(['data' => collect(['first_name' => 'Maria'])])->id,
            'payment' => 30,
            'payment_surcharge' => 0,
            'total' => 90,
            'payment_gateway' => 'offline',
        ]);

        $extra = Extra::factory()->create(['name' => 'High chair']);
        $reservation->extras()->attach($extra->id, ['quantity' => 2, 'price' => 10]);

        $option = Option::factory()->create(['name' => 'Seating', 'item_id' => $item->id()]);
        $value = OptionValue::factory()->create(['option_id' => $option->id, 'name' => 'Outside']);
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $html = (new ReservationPaymentRequest($reservation))->render();

        $this->assertStringContainsString('Hi Maria,', $html);
        $this->assertStringContainsString('High chair', $html);
        $this->assertStringContainsString('x2', $html);
        $this->assertStringContainsString('Seating', $html);
        $this->assertStringContainsString('Outside', $html);
        $this->assertStringContainsString('Remaining amount', $html);
        $this->assertStringContainsString('60.00', $html);
        $this->assertStringNotContainsString('Payment surcharge', $html);
    }

    public function test_no_greeting_without_a_first_name()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory()->create([
            'status' => 'awaiting_payment',
            'item_id' => $item->id(),
            'customer_id' => Customer::factory()->create(['data' => collect(['last_name' => 'Doe'])])->id,
            'payment' => 30,
            'total' => 30,
            'payment_gateway' => 'offline',
        ]);

        $html = (new ReservationPaymentRequest($reservation))->render();

        $this->assertStringNotContainsString('Hi ', $html);
        $this->assertStringContainsString('We have created a reservation for you.', $html);
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

        // The payment page went away after creation: the resend must refuse, not send offline wording or stamp a phantom send.
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

        // The recorded gateway was removed after creation: the resend must refuse rather than email a dead-end "Pay now" link.
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

        // Deadline lapsed but not yet swept: the pay page refuses the link, so the resend must refuse too.
        $reservation->update(['hold_expires_at' => now()->subHour()]);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The payment deadline for this reservation has passed, so a payment request cannot be sent.');

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_resend_endpoint_rejects_once_the_arrival_day_has_passed()
    {
        Mail::fake();
        $this->signInAdmin();
        $this->withExceptionHandling();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        // No deadline, but the stay has begun: the pay page refuses the link, so the resend must refuse too.
        Reservation::whereKey($reservation->id)->update([
            'date_start' => now()->subDays(2)->startOfDay(),
            'date_end' => now()->addDay()->startOfDay(),
        ]);

        $this->postJson(cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]))
            ->assertStatus(422);

        Mail::assertNotSent(ReservationPaymentRequest::class);
        $this->assertNull($reservation->fresh()->payment_request_email_sent_at);
    }

    public function test_send_refuses_when_the_reservation_left_awaiting_payment_after_the_pre_check()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'send_payment_request_email' => false,
        ]));

        // Regression: a webhook confirm after the isAwaitingPayment() pre-check leaves the model stale — the send must re-read the row and refuse.
        Reservation::whereKey($reservation->id)->update(['status' => 'confirmed']);
        $this->assertTrue($reservation->isAwaitingPayment());

        try {
            $this->creator()->sendPaymentRequestEmail($reservation);
            $this->fail('The stale-status send should have been refused.');
        } catch (ManualReservationException $e) {
            $this->assertSame(
                'This reservation is no longer awaiting payment, so a payment request cannot be sent.',
                $e->getMessage()
            );
        }

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

        // The template itself must not imply a bank-transfer flow for an online reservation that lost its pay link.
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

<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\ReservationPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\Support\FakeRedirectGateway;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationPaymentPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class, 'label' => 'Fake Online'],
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $page = $this->makeStatamicItem();
        Config::set('resrv-config.manual_reservations_payment_entry', [$page->id()]);
    }

    protected function makeAwaitingReservation(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'item_id' => $item->id(),
            'payment' => 50,
            'payment_surcharge' => 5,
            'payment_gateway' => 'fake',
            'payment_id' => '',
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
        ], $attributes));
    }

    protected function fakeGateway(): FakePaymentGateway
    {
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->createdIntents = [];
        $gateway->cancelledIntents = [];

        return $gateway;
    }

    public function test_valid_link_renders_the_awaiting_state_with_the_stored_amount()
    {
        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSet('reservationId', $reservation->id)
            ->assertSet('linkFailed', false)
            ->assertSee(trans('statamic-resrv::frontend.completeYourPayment'))
            ->assertSee(trans('statamic-resrv::frontend.amountToPay'))
            ->assertSee('55.00')
            ->assertSee($reservation->reference)
            ->assertSee(trans('statamic-resrv::frontend.paymentMethodIs', ['method' => 'Fake Online']))
            // The button disables itself while pay() runs so a double click can't fire a second request.
            ->assertSeeHtml('wire:loading.attr="disabled"')
            ->assertSeeHtml('wire:target="pay"');
    }

    public function test_the_amount_and_deadline_lead_the_details()
    {
        $deadline = now()->addDays(2)->setTime(17, 30);
        $reservation = $this->makeAwaitingReservation(['hold_expires_at' => $deadline]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeInOrder([
                trans('statamic-resrv::frontend.amountToPay'),
                '55.00',
                trans('statamic-resrv::frontend.paymentDueBy', ['date' => $deadline->format('d-m-Y H:i T')]),
                trans('statamic-resrv::frontend.bookingReference'),
                $reservation->reference,
                trans('statamic-resrv::frontend.reservationPeriod'),
            ])
            // The deadline is an instant: it carries its time zone.
            ->assertSee($deadline->format('d-m-Y H:i').' '.$deadline->format('T'));
    }

    public function test_the_due_line_names_the_arrival_day_when_it_closes_payment_before_the_hold()
    {
        $arrival = now()->addDays(2)->setTime(12, 0);
        $reservation = $this->makeAwaitingReservation([
            'date_start' => $arrival,
            'date_end' => $arrival->copy()->addDays(2),
            'hold_expires_at' => now()->addDays(5)->setTime(9, 0),
        ]);

        // The pay page refuses once the arrival day ends, so it must not promise the later hold deadline.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentDueByArrival', ['date' => $arrival->copy()->endOfDay()->format('d-m-Y H:i T')]))
            ->assertDontSee($reservation->hold_expires_at->format('d-m-Y H:i'));
    }

    public function test_no_due_line_without_a_hold()
    {
        $reservation = $this->makeAwaitingReservation(['hold_expires_at' => null]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.amountToPay'))
            ->assertDontSee('Please pay by');
    }

    public function test_the_state_heading_is_a_heading_element()
    {
        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeHtml('<h2 class="text-lg xl:text-xl font-medium">');
    }

    public function test_the_pay_button_hides_its_label_behind_the_spinner_while_paying()
    {
        $reservation = $this->makeAwaitingReservation();

        // The spinner overlays the button: the label must go invisible, not smear underneath it.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeHtml('<span wire:loading.class="invisible" wire:target="pay">');
    }

    public function test_a_partial_request_shows_the_total_and_the_remaining_balance()
    {
        $reservation = $this->makeAwaitingReservation([
            'payment' => 25,
            'payment_surcharge' => 0,
            'total' => 70,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeInOrder([
                '25.00',
                trans('statamic-resrv::frontend.total'),
                '70.00',
                trans('statamic-resrv::frontend.remainingBalance'),
                '45.00',
                trans('statamic-resrv::frontend.remainingBalanceNote'),
            ])
            ->assertDontSee(trans('statamic-resrv::frontend.paymentSurcharge'));
    }

    public function test_a_full_request_shows_one_amount_without_a_duplicate_total_or_balance()
    {
        $reservation = $this->makeAwaitingReservation([
            'payment' => 70,
            'payment_surcharge' => 0,
            'total' => 70,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee('70.00')
            ->assertDontSee(trans('statamic-resrv::frontend.total'))
            ->assertDontSee(trans('statamic-resrv::frontend.remainingBalance'));
    }

    public function test_the_total_shows_when_a_partial_request_plus_its_surcharge_equals_it()
    {
        // 100 now + 3 surcharge = 103 = total, yet 3 stays unpaid: the total must still be stated.
        $reservation = $this->makeAwaitingReservation([
            'payment' => 100,
            'payment_surcharge' => 3,
            'total' => 103,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeInOrder([
                trans('statamic-resrv::frontend.amountToPay'),
                '103.00',
                trans('statamic-resrv::frontend.total'),
                '103.00',
                trans('statamic-resrv::frontend.paymentSurcharge'),
                '3.00',
                trans('statamic-resrv::frontend.remainingBalance'),
                '3.00',
            ]);
    }

    public function test_a_surcharge_is_explained_next_to_the_total()
    {
        $reservation = $this->makeAwaitingReservation([
            'payment' => 50,
            'payment_surcharge' => 5,
            'total' => 50,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeInOrder([
                '55.00',
                trans('statamic-resrv::frontend.total'),
                '50.00',
                trans('statamic-resrv::frontend.paymentSurcharge'),
                '5.00',
            ])
            ->assertDontSee(trans('statamic-resrv::frontend.remainingBalance'));
    }

    public function test_absent_or_invalid_hash_fails_the_link()
    {
        $reservation = $this->makeAwaitingReservation();

        Livewire::test(ReservationPayment::class)
            ->assertSet('linkFailed', true)
            ->assertSee(trans('statamic-resrv::frontend.paymentLinkFailed'));

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('0', 64)])
            ->test(ReservationPayment::class)
            ->assertSet('linkFailed', true)
            ->assertSet('reservationId', null);
    }

    public function test_lookups_are_rate_limited_after_ten_failures()
    {
        $reservation = $this->makeAwaitingReservation();

        foreach (range(1, 10) as $i) {
            Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('0', 64)])
                ->test(ReservationPayment::class)
                ->assertSet('linkFailed', true);
        }

        // Budget exhausted: even the correct hash no longer resolves.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSet('linkFailed', true)
            ->assertSet('reservationId', null);
    }

    public function test_deadline_passed_link_shows_expired_state_and_refuses_payment()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation([
            'hold_expires_at' => now()->subHour(),
        ]);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingExpired'))
            ->assertSee(trans('statamic-resrv::frontend.paymentLinkExpired'))
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.completeYourPayment'))
            ->assertDontSee(trans('statamic-resrv::frontend.amountToPay'));

        // The pay action must refuse even when invoked directly — the hidden button is no guard.
        $component->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('', $reservation->fresh()->payment_id);
    }

    public function test_dead_end_states_show_the_business_contact_details()
    {
        Config::set('resrv-config.mail', 'desk@example.com');
        Config::set('resrv-config.phone', '+30 210 123 4567');

        $expired = $this->makeAwaitingReservation(['hold_expires_at' => now()->subHour()]);
        $arrivalPassed = $this->makeAwaitingReservation([
            'date_start' => now()->subDay()->startOfDay(),
            'date_end' => now()->addDay()->startOfDay(),
        ]);
        $cancelled = $this->makeAwaitingReservation(['status' => ReservationStatus::CANCELLED->value]);

        foreach ([
            [$expired, 'paymentLinkExpired'],
            [$arrivalPassed, 'paymentArrivalPassed'],
            [$cancelled, 'paymentUnavailable'],
        ] as [$reservation, $message]) {
            // Every "please contact us" must come with a way to do so.
            Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
                ->test(ReservationPayment::class)
                ->assertSeeInOrder([
                    trans('statamic-resrv::frontend.'.$message),
                    'desk@example.com',
                    '+30 210 123 4567',
                ])
                ->assertSeeHtml('href="mailto:desk@example.com"')
                ->assertSeeHtml('href="tel:+302101234567"');
        }

        Livewire::withQueryParams([])
            ->test(ReservationPayment::class)
            ->assertSet('linkFailed', true)
            ->assertSee(trans('statamic-resrv::frontend.paymentLinkFailed'))
            ->assertSee('desk@example.com');
    }

    public function test_the_awaiting_state_shows_no_contact_block()
    {
        Config::set('resrv-config.mail', 'desk@example.com');

        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertDontSeeHtml('href="mailto:desk@example.com"');
    }

    public function test_a_passed_arrival_day_closes_payment_even_without_a_deadline()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation([
            'hold_expires_at' => null,
            'date_start' => now()->subDay()->startOfDay(),
            'date_end' => now()->addDay()->startOfDay(),
        ]);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingExpired'))
            ->assertSee(trans('statamic-resrv::frontend.paymentArrivalPassed'))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentLinkExpired'))
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.amountToPay'));

        $component->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('', $reservation->fresh()->payment_id);
    }

    public function test_the_arrival_day_itself_stays_payable()
    {
        $gateway = $this->fakeGateway();

        // Date-only stays start at midnight: payment stays open until that day ends.
        $reservation = $this->makeAwaitingReservation([
            'date_start' => today(),
            'date_end' => today()->addDays(2),
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertDontSee(trans('statamic-resrv::frontend.paymentArrivalPassed'))
            ->call('pay')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment');

        $this->assertCount(1, $gateway->createdIntents);
    }

    public function test_a_return_reporting_money_in_flight_wins_over_a_closed_window()
    {
        $this->fakeGateway()->retrievedIntentStatus = 'succeeded';

        $reservation = $this->makeAwaitingReservation([
            'payment_id' => 'pi_processing',
            'hold_expires_at' => now()->subMinute(),
        ]);

        Livewire::withQueryParams([
            'ref' => $reservation->reference,
            'hash' => $reservation->customerLookupHash(),
            'payment_intent' => 'pi_processing',
            'redirect_status' => 'succeeded',
        ])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentProcessing'))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentLinkExpired'));
    }

    public function test_past_the_window_a_return_the_gateway_does_not_back_shows_the_closed_window()
    {
        // The stored intent never collected: a stale or edited return URL must not claim it did.
        $this->fakeGateway()->retrievedIntentStatus = 'requires_payment_method';

        $withIntent = $this->makeAwaitingReservation([
            'payment_id' => 'pi_unpaid',
            'hold_expires_at' => now()->subMinute(),
        ]);
        $withoutIntent = $this->makeAwaitingReservation([
            'date_start' => now()->subDay()->startOfDay(),
            'date_end' => now()->addDay()->startOfDay(),
        ]);

        Livewire::withQueryParams([
            'ref' => $withIntent->reference,
            'hash' => $withIntent->customerLookupHash(),
            'payment_intent' => 'pi_unpaid',
            'redirect_status' => 'succeeded',
        ])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentLinkExpired'))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentProcessing'));

        Livewire::withQueryParams([
            'ref' => $withoutIntent->reference,
            'hash' => $withoutIntent->customerLookupHash(),
            'payment_intent' => 'pi_forged',
        ])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentArrivalPassed'))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentProcessing'));
    }

    public function test_pay_creates_an_intent_and_persists_the_payment_id()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('amount', 55.0)
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment');

        $this->assertCount(1, $gateway->createdIntents);

        $fresh = $reservation->fresh();
        $this->assertNotSame('', $fresh->payment_id);
        $this->assertSame($gateway->createdIntents[0]['payment_id'], $fresh->payment_id);
    }

    public function test_second_pay_reuses_the_existing_intent()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $paymentId = $reservation->fresh()->payment_id;

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('clientSecret', 'cs_'.$paymentId);

        $this->assertCount(1, $gateway->createdIntents, 'A second pay must resume the intent, not create another.');
        $this->assertSame($paymentId, $reservation->fresh()->payment_id);
    }

    public function test_pay_treats_a_held_requires_capture_intent_as_processing()
    {
        $gateway = $this->fakeGateway();
        $gateway->retrievedIntentStatus = 'requires_capture';

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_authorized']);

        // A held authorization must show the processing state — never remount a form or void-and-replace secured money.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentProcessing', true)
            ->assertSet('clientSecret', '')
            ->assertSee(trans('statamic-resrv::frontend.paymentProcessing'));

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertCount(0, $gateway->cancelledIntents);
        $this->assertSame('pi_authorized', $reservation->fresh()->payment_id);
    }

    public function test_pay_remints_when_a_resumed_inline_intent_lacks_a_client_secret()
    {
        $gateway = $this->fakeGateway();
        $gateway->retrieveOmitsClientSecret = true;

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_bare']);

        // A spec-minimum retrieve (status only) cannot mount an inline form: the stale intent must be voided and replaced.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment')
            ->assertNotSet('clientSecret', '');

        $this->assertContains('pi_bare', array_column($gateway->cancelledIntents, 'payment_id'), 'The unmountable intent must be voided before it is replaced.');
        $this->assertCount(1, $gateway->createdIntents);
        $this->assertSame($gateway->createdIntents[0]['payment_id'], $reservation->fresh()->payment_id);
    }

    public function test_pay_refuses_to_remint_when_the_superseded_intent_cannot_be_voided()
    {
        $gateway = $this->fakeGateway();
        $gateway->retrieveOmitsClientSecret = true;
        $gateway->cancelSucceeds = false;

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_bare']);

        // The provider swallows the void and the intent stays live; minting a replacement would leave TWO chargeable intents, so the attempt must fail retryably.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentError', true)
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '');

        $this->assertContains('pi_bare', array_column($gateway->cancelledIntents, 'payment_id'));
        $this->assertCount(0, $gateway->createdIntents, 'No replacement may be minted next to a possibly-live intent.');
        $this->assertSame('pi_bare', $reservation->fresh()->payment_id);
    }

    public function test_pay_shows_processing_when_the_superseded_intent_succeeds_during_the_void()
    {
        $gateway = $this->fakeGateway();
        $gateway->retrieveOmitsClientSecret = true;
        $gateway->cancelSucceeds = false;

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_bare']);

        // The payment succeeds between the resume read and the void: show processing and keep the reference — never mint a second intent.
        $gateway->onRetrievePaymentIntent = function () use ($gateway) {
            $gateway->retrievedIntentStatus = 'succeeded';
        };

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentProcessing', true)
            ->assertSet('paymentError', false)
            ->assertSet('clientSecret', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('pi_bare', $reservation->fresh()->payment_id);
    }

    public function test_a_failing_intent_read_on_resume_never_mints_a_second_intent()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_live']);

        // A transient provider failure on the resume read must surface an error, never fall through to minting next to a live intent.
        $gateway->onRetrievePaymentIntent = function () {
            throw new \RuntimeException('Gateway timed out.');
        };

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentError', true)
            ->assertSet('clientSecret', '')
            ->assertSet('paymentView', '')
            ->assertSee(trans('statamic-resrv::frontend.paymentMountFailed'))
            ->assertSeeHtml('role="alert"');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertCount(0, $gateway->cancelledIntents);
        $this->assertSame('pi_live', $reservation->fresh()->payment_id);
    }

    public function test_pay_falls_back_to_the_default_gateway_when_none_is_recorded()
    {
        $gateway = $this->fakeGateway();

        // A blank/legacy gateway column must fall back to the default, like resolvePaymentGateway() everywhere else.
        $reservation = $this->makeAwaitingReservation(['payment_gateway' => '']);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentError', false)
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment')
            ->assertSet('amount', 55.0);

        $this->assertCount(1, $gateway->createdIntents);

        $fresh = $reservation->fresh();
        $this->assertSame($gateway->createdIntents[0]['payment_id'], $fresh->payment_id);

        // The gateway key must be stamped alongside the intent id, or the void/reconcile guards no-op on the blank column.
        $this->assertSame('fake', $fresh->payment_gateway);
    }

    public function test_cancelling_after_a_default_gateway_payment_attempt_voids_the_minted_intent()
    {
        Mail::fake();
        $this->signInAdmin();
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => '']);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $mintedId = $reservation->fresh()->payment_id;
        $this->assertNotSame('', $mintedId);

        // Regression: the cancel path's void guard no-opped on the blank gateway column, leaving the live intent completable.
        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertOk();

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::CANCELLED->value, $fresh->status);
        $this->assertContains($mintedId, array_column($gateway->cancelledIntents, 'payment_id'));
        $this->assertSame('', $fresh->payment_id);
    }

    public function test_out_of_band_confirm_after_a_default_gateway_payment_attempt_voids_the_minted_intent()
    {
        Mail::fake();
        $this->signInAdmin();
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => '']);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $mintedId = $reservation->fresh()->payment_id;
        $this->assertNotSame('', $mintedId);

        // settlePaidOutOfBand must void the minted intent through the stamped default gateway and clear the reference.
        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertOk();

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::CONFIRMED->value, $fresh->status);
        $this->assertContains($mintedId, array_column($gateway->cancelledIntents, 'payment_id'));
        $this->assertSame('', $fresh->payment_id);
    }

    public function test_pay_aborts_and_voids_the_intent_when_the_reservation_is_cancelled_mid_flight()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        // A cancel commits during the gateway round-trip, after pay()'s outer guard: the minted intent must be voided and never written.
        // Cache::lock only serialises pay() calls — the write must re-verify payability under the transition's DB row lock.
        $gateway->onPaymentIntent = function (Reservation $r) {
            $r->transitionTo(ReservationStatus::CANCELLED);
        };

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '')
            ->assertSet('paymentError', false);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::CANCELLED->value, $fresh->status);

        // The intent was minted but never committed to the row...
        $this->assertSame('', $fresh->payment_id);
        $this->assertCount(1, $gateway->createdIntents);

        // ...and it was voided so it can't be completed against a cancelled booking.
        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertSame($gateway->createdIntents[0]['payment_id'], $gateway->cancelledIntents[0]['payment_id']);
    }

    public function test_a_cancel_landing_during_the_mint_renders_the_cancelled_state_not_the_pay_form()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        // The catch path must bust the cached reservation computed so this same render shows the cancelled state.
        // DB-level update simulates the cross-process cancel; transitionTo on the shared instance would mask the staleness.
        $gateway->onPaymentIntent = function (Reservation $r) {
            DB::table('resrv_reservations')
                ->where('id', $r->id)
                ->update(['status' => ReservationStatus::CANCELLED->value]);
        };

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', '')
            ->assertSee(trans('statamic-resrv::frontend.paymentUnavailable'))
            ->assertDontSee(trans('statamic-resrv::frontend.amountToPay'));

        $this->assertSame(ReservationStatus::CANCELLED->value, $reservation->fresh()->status);
        $this->assertCount(1, $gateway->cancelledIntents);
    }

    public function test_pay_refuses_to_resume_an_intent_after_a_cancel_lands_behind_the_guard()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_live_race']);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class);

        // A cancel lands right after pay()'s outer guard: resuming must hit the locked payability re-check or the customer gets a chargeable secret for a cancelled booking.
        Reservation::retrieved(function (Reservation $model) use ($reservation) {
            if ((int) $model->id === $reservation->id && $model->status === ReservationStatus::AWAITING_PAYMENT->value) {
                DB::table('resrv_reservations')
                    ->where('id', $reservation->id)
                    ->update(['status' => ReservationStatus::CANCELLED->value]);
            }
        });

        $component->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '')
            ->assertSet('paymentError', false);

        $this->assertCount(0, $gateway->createdIntents);
        // Disposal of the stored intent belongs to the cancelling transition, not the pay path.
        $this->assertCount(0, $gateway->cancelledIntents);
    }

    public function test_pay_refuses_a_resumed_intent_when_a_cancel_lands_during_the_retrieve()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_live_retrieve_race']);

        // The cancel commits during the resume's gateway round-trip: the fresh-row re-check must refuse to hand out the secret.
        $gateway->onRetrievePaymentIntent = function (Reservation $r) {
            $r->transitionTo(ReservationStatus::CANCELLED);
        };

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '')
            ->assertSet('paymentError', false);

        $this->assertSame(ReservationStatus::CANCELLED->value, $reservation->fresh()->status);
        $this->assertCount(0, $gateway->createdIntents);
        // The pay path must not void the stored intent — the cancelling transition owns its disposal.
        $this->assertCount(0, $gateway->cancelledIntents);
    }

    public function test_full_round_trip_pay_webhook_confirm_shows_paid()
    {
        $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $this->post(route('resrv.webhook.gateway.store', ['gateway' => 'fake', 'reservation_id' => $reservation->id, 'status' => 'success']))
            ->assertStatus(200);

        $this->assertSame('confirmed', $reservation->fresh()->status);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingConfirmed'))
            ->assertSee(trans('statamic-resrv::frontend.paymentAlreadyCompleted'))
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.completeYourPayment'))
            ->assertDontSee(trans('statamic-resrv::frontend.amountToPay'));
    }

    public function test_offline_gateway_shows_instructions_and_never_creates_an_intent()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'offline',
        ]);

        Config::set('resrv-config.mail', 'desk@example.com');

        // Without the instructions setting the page must not point at instructions that don't exist.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingInstructions'))
            ->assertSee(trans('statamic-resrv::frontend.paymentOfflineNoInstructions', ['reference' => $reservation->reference]))
            ->assertSee(trans('statamic-resrv::frontend.paymentMethodIs', ['method' => 'Bank Transfer']))
            ->assertSee('desk@example.com')
            ->assertDontSee('instructions in your email')
            ->assertDontSee(trans('statamic-resrv::frontend.paymentLinkExpired'))
            ->call('pay')
            ->assertSet('paymentView', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('', $reservation->fresh()->payment_id);
    }

    public function test_offline_instructions_setting_renders_escaped_with_line_breaks()
    {
        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: ACME <b>Ltd</b>\nIBAN: GR12 3456");

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => 'offline']);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeHtml('Bank: ACME &lt;b&gt;Ltd&lt;/b&gt;<br />')
            ->assertSeeHtml('IBAN: GR12 3456')
            ->assertDontSeeHtml('<b>Ltd</b>')
            ->assertSee(trans('statamic-resrv::frontend.paymentOfflineInstructions', ['reference' => $reservation->reference]))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentOfflineNoInstructions', ['reference' => $reservation->reference]));
    }

    public function test_offline_instructions_stay_literal_like_the_email()
    {
        Config::set('resrv-config.manual_reservations_offline_instructions', "1. Transfer to *ACME* [here](https://x.test)\n- IBAN: GR12 & co");

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => 'offline']);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSeeHtml('1. Transfer to *ACME* [here](https://x.test)<br />')
            ->assertSeeHtml('- IBAN: GR12 &amp; co')
            ->assertDontSeeHtml('<em>ACME</em>');
    }

    public function test_offline_instructions_never_show_for_online_gateways()
    {
        Config::set('resrv-config.manual_reservations_offline_instructions', 'IBAN: GR12 3456');

        $reservation = $this->makeAwaitingReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertDontSee('IBAN: GR12 3456')
            ->assertSee(trans('statamic-resrv::frontend.pay'));
    }

    public function test_cancelled_reservation_link_shows_unavailable()
    {
        $reservation = $this->makeAwaitingReservation([
            'status' => ReservationStatus::CANCELLED->value,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingUnavailable'))
            ->assertSee(trans('statamic-resrv::frontend.paymentUnavailable'))
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.amountToPay'))
            ->assertDontSee(trans('statamic-resrv::frontend.completeYourPayment'));
    }

    public function test_return_from_gateway_shows_processing_while_still_awaiting()
    {
        $reservation = $this->makeAwaitingReservation([
            'payment_id' => 'pi_processing',
        ]);

        Livewire::withQueryParams([
            'ref' => $reservation->reference,
            'hash' => $reservation->customerLookupHash(),
            'payment_intent' => 'pi_processing',
            'redirect_status' => 'processing',
        ])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentHeadingProcessing'))
            ->assertSee(trans('statamic-resrv::frontend.paymentProcessing'));
    }

    protected function useRedirectGateway(): void
    {
        Config::set('resrv-config.payment_gateways', [
            'fakeredirect' => ['class' => FakeRedirectGateway::class, 'label' => 'Fake Redirect'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);
    }

    public function test_pay_via_redirect_gateway_bakes_the_pay_page_return_url_into_the_intent()
    {
        $this->useRedirectGateway();

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => 'fakeredirect']);

        // A redirect gateway must return to this pay page with the resrv_gateway marker baked into the base URL.
        $expectedReturn = $reservation->customerPaymentUrl();
        $this->assertNotNull($expectedReturn);
        $expectedReturn .= (str_contains($expectedReturn, '?') ? '&' : '?').'resrv_gateway=fakeredirect';

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', ''); // redirect gateways never mount an embedded view

        $gateway = app(PaymentGatewayManager::class)->gateway('fakeredirect');
        $this->assertSame($expectedReturn, $gateway->lastReturnUrl);

        $fresh = $reservation->fresh();
        $this->assertNotSame('', $fresh->payment_id);

        // The provider URL is used verbatim — appending params could invalidate a signed URL.
        $component->assertRedirect('https://provider.test/checkout/'.$fresh->payment_id);
    }

    public function test_pay_resumes_a_redirect_intent_that_carries_a_provider_url()
    {
        $this->useRedirectGateway();

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'fakeredirect',
            'payment_id' => 'redir_stale',
        ]);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $gateway = app(PaymentGatewayManager::class)->gateway('fakeredirect');

        $this->assertCount(0, $gateway->createdIntents, 'A resumable redirect intent must be reused, not replaced.');
        $this->assertSame('redir_stale', $reservation->fresh()->payment_id);
        $component->assertRedirect('https://provider.test/checkout/redir_stale');
    }

    public function test_pay_remints_when_a_resumed_redirect_intent_lacks_a_provider_url()
    {
        $this->useRedirectGateway();

        // A spec-minimum retrieve has no provider URL: the intent must be voided and re-minted, never redirected to a dead URL.
        $gateway = app(PaymentGatewayManager::class)->gateway('fakeredirect');
        $gateway->retrieveIncludesRedirectTo = false;

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'fakeredirect',
            'payment_id' => 'redir_stale',
        ]);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay');

        $this->assertContains('redir_stale', array_column($gateway->cancelledIntents, 'payment_id'), 'The unmountable intent must be voided before it is replaced.');
        $this->assertCount(1, $gateway->createdIntents);
        $expectedReturn = $reservation->customerPaymentUrl();
        $expectedReturn .= (str_contains($expectedReturn, '?') ? '&' : '?').'resrv_gateway=fakeredirect';
        $this->assertSame($expectedReturn, $gateway->lastReturnUrl);

        $fresh = $reservation->fresh();
        $this->assertNotSame('redir_stale', $fresh->payment_id);
        $this->assertNotSame('', $fresh->payment_id);
        $component->assertRedirect('https://provider.test/checkout/'.$fresh->payment_id);
    }

    public function test_return_from_redirect_gateway_shows_processing_while_still_awaiting()
    {
        $this->useRedirectGateway();

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'fakeredirect',
            'payment_id' => 'redir_existing',
        ]);

        // Return carries resrv_gateway; handleRedirectBack maps status=success to processing.
        Livewire::withQueryParams([
            'ref' => $reservation->reference,
            'hash' => $reservation->customerLookupHash(),
            'resrv_gateway' => 'fakeredirect',
            'status' => 'success',
        ])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentProcessing'));

        // The redirect-back never confirms — that's the webhook's job.
        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->fresh()->status);
    }

    public function test_failed_return_from_redirect_gateway_falls_back_to_awaiting_for_retry()
    {
        $this->useRedirectGateway();

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'fakeredirect',
            'payment_id' => 'redir_existing',
        ]);

        Livewire::withQueryParams([
            'ref' => $reservation->reference,
            'hash' => $reservation->customerLookupHash(),
            'resrv_gateway' => 'fakeredirect',
            'status' => 'fail',
        ])
            ->test(ReservationPayment::class)
            ->assertDontSee(trans('statamic-resrv::frontend.paymentProcessing'))
            ->assertSee(trans('statamic-resrv::frontend.pay'));
    }

    public function test_customer_payment_url_null_matrix()
    {
        // Unconfigured entry.
        Config::set('resrv-config.manual_reservations_payment_entry', null);
        $reservation = $this->makeAwaitingReservation();
        $this->assertNull($reservation->customerPaymentUrl());

        // Draft entry.
        $draft = $this->makeStatamicItem();
        $draft->published(false)->save();
        Config::set('resrv-config.manual_reservations_payment_entry', [$draft->id()]);
        $this->assertNull($reservation->fresh()->customerPaymentUrl());

        // No customer email.
        $page = $this->makeStatamicItem();
        Config::set('resrv-config.manual_reservations_payment_entry', [$page->id()]);
        $item = $this->makeStatamicItem();
        $noCustomer = Reservation::factory()->create([
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'item_id' => $item->id(),
        ]);
        $this->assertNull($noCustomer->customerPaymentUrl());

        // Fully configured.
        $url = $reservation->fresh()->customerPaymentUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('ref='.$reservation->reference, $url);
        $this->assertStringContainsString('hash='.$reservation->customerLookupHash(), $url);
    }
}

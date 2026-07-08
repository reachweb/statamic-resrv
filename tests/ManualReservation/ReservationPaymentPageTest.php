<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\ReservationPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\Support\FakeRedirectGateway;
use Reach\StatamicResrv\Tests\Support\LegacyThreeArgGateway;
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
            ->assertSee(trans('statamic-resrv::frontend.amountToPay'))
            ->assertSee('55.00')
            ->assertSee($reservation->reference);
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
            ->assertSee(trans('statamic-resrv::frontend.paymentLinkExpired'));

        // The pay action must refuse even when invoked directly — the hidden button is no guard.
        $component->call('pay')
            ->assertSet('paymentView', '')
            ->assertSet('clientSecret', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('', $reservation->fresh()->payment_id);
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

    public function test_pay_aborts_and_voids_the_intent_when_the_reservation_is_cancelled_mid_flight()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation();

        // The hold-lapse sweep / a CP cancel commits DURING the gateway round-trip — after pay()'s
        // outer guard already passed. The freshly-minted intent must be voided and payment_id must NOT
        // be written, so a terminal booking can never carry a chargeable intent the customer could
        // still complete. (Cache::lock only serialises concurrent pay() calls; the transition rides a
        // separate DB row lock, so the write must re-verify payability under that same lock.)
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

    public function test_pay_refuses_to_resume_an_intent_after_a_cancel_lands_behind_the_guard()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation(['payment_id' => 'pi_live_race']);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class);

        // A CP cancel commits right after pay()'s outer guard hydrates the row, while its
        // intent void hasn't landed (or failed tolerantly) so the stored intent is still live
        // at the provider. Resuming must go through the locked payability re-check — without
        // it the customer gets a chargeable secret for a cancelled booking.
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

        // The cancel commits DURING the resume's gateway round-trip — after the under-lock
        // payability check already passed. The fresh-row re-check before handing out the
        // secret must refuse the still-live intent.
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
        // The pay path must NOT void the stored intent: the cancelling transition owns its
        // disposal, and settlePaidOutOfBand deliberately keeps a capturing intent's reference.
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
            ->assertSee(trans('statamic-resrv::frontend.paymentAlreadyCompleted'));
    }

    public function test_offline_gateway_shows_instructions_and_never_creates_an_intent()
    {
        $gateway = $this->fakeGateway();

        $reservation = $this->makeAwaitingReservation([
            'payment_gateway' => 'offline',
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentOfflineInstructions'))
            ->assertDontSee(trans('statamic-resrv::frontend.paymentLinkExpired'))
            ->call('pay')
            ->assertSet('paymentView', '');

        $this->assertCount(0, $gateway->createdIntents);
        $this->assertSame('', $reservation->fresh()->payment_id);
    }

    public function test_cancelled_reservation_link_shows_unavailable()
    {
        $reservation = $this->makeAwaitingReservation([
            'status' => ReservationStatus::CANCELLED->value,
        ]);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->assertSee(trans('statamic-resrv::frontend.paymentUnavailable'));
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

        // A redirect gateway must return the customer to this pay-by-link page, not checkout-complete.
        $expectedReturn = $reservation->customerPaymentUrl();
        $this->assertNotNull($expectedReturn);

        $component = Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', ''); // redirect gateways never mount an embedded view

        $gateway = app(PaymentGatewayManager::class)->gateway('fakeredirect');
        $this->assertSame($expectedReturn, $gateway->lastReturnUrl);

        $fresh = $reservation->fresh();
        $this->assertNotSame('', $fresh->payment_id);

        // Assert the exact outbound URL, including the resrv_gateway tag the return leg needs to
        // resolve the gateway (a bare assertRedirect() would let that tag be dropped).
        $component->assertRedirect('https://provider.test/checkout/'.$fresh->payment_id.'?resrv_gateway=fakeredirect');
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
        $component->assertRedirect('https://provider.test/checkout/redir_stale?resrv_gateway=fakeredirect');
    }

    public function test_pay_remints_when_a_resumed_redirect_intent_lacks_a_provider_url()
    {
        $this->useRedirectGateway();

        // A Step-13-minimum gateway: retrievePaymentIntent() exposes only ->status, so the
        // resumed intent has no provider URL to forward the customer to. The pay flow must
        // void it and mint a fresh intent (which does carry ->redirectTo) — never redirect
        // the customer to a dead URL.
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
        $this->assertSame($reservation->customerPaymentUrl(), $gateway->lastReturnUrl);

        $fresh = $reservation->fresh();
        $this->assertNotSame('redir_stale', $fresh->payment_id);
        $this->assertNotSame('', $fresh->payment_id);
        $component->assertRedirect('https://provider.test/checkout/'.$fresh->payment_id.'?resrv_gateway=fakeredirect');
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

    public function test_a_three_parameter_gateway_still_works_through_the_manual_pay_flow()
    {
        Config::set('resrv-config.payment_gateways', [
            'legacy3' => ['class' => LegacyThreeArgGateway::class, 'label' => 'Legacy'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = $this->makeAwaitingReservation(['payment_gateway' => 'legacy3']);

        // Core calls paymentIntent() with a 4th arg; a 3-param gateway ignores it and still works.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationPayment::class)
            ->call('pay')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment');

        $fresh = $reservation->fresh();
        $this->assertNotSame('', $fresh->payment_id);
        $this->assertStringStartsWith('legacy_', $fresh->payment_id);
    }

    public function test_calling_a_three_parameter_gateway_with_four_positional_args_does_not_error()
    {
        $reservation = $this->makeAwaitingReservation();
        $gateway = new LegacyThreeArgGateway;

        // PHP-level proof: a 3-param method invoked with a 4th positional arg silently ignores it.
        $intent = $gateway->paymentIntent($reservation->amountDue(), $reservation, collect(), 'https://return.test/pay');

        $this->assertTrue($gateway->created);
        $this->assertIsString($intent->id);
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

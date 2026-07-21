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

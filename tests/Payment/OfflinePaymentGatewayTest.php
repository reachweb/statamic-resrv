<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\CheckoutPayment;
use Reach\StatamicResrv\Mail\ReservationAwaitingPayment;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Mail\ReservationPaymentRequest;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class OfflinePaymentGatewayTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    // --- Unit tests for the gateway class ---

    public function test_implements_payment_interface()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertInstanceOf(PaymentInterface::class, $gateway);
    }

    public function test_name_returns_offline()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertEquals('offline', $gateway->name());
    }

    public function test_label_returns_default_label()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertNotEmpty($gateway->label());
    }

    public function test_payment_view_returns_offline_view()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertEquals('statamic-resrv::livewire.checkout-payment-offline', $gateway->paymentView());
    }

    public function test_refund_returns_true()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertTrue($gateway->refund($this->reservation));
    }

    public function test_supports_webhooks_returns_false()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertFalse($gateway->supportsWebhooks());
    }

    public function test_redirects_for_payment_returns_false()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertFalse($gateway->redirectsForPayment());
    }

    public function test_get_public_key_returns_empty_string()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertSame('', $gateway->getPublicKey($this->reservation));
    }

    // --- Integration tests (Livewire checkout flow) ---

    public function test_offline_gateway_initializes_correctly_in_checkout()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', 'offline')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment-offline');

        // Nothing is collected online, so no payment intent is created.
        $reservation = Reservation::find($this->reservation->id);
        $this->assertSame('', $reservation->payment_id);
        $this->assertEquals('offline', $reservation->payment_gateway);
    }

    public function test_offline_gateway_in_multi_gateway_picker()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->assertSet('availableGateways', function ($gateways) {
                return count($gateways) === 2
                    && $gateways[0]['name'] === 'fake'
                    && $gateways[1]['name'] === 'offline';
            })
            ->dispatch('gateway-selected', gateway: 'offline')
            ->assertSet('selectedGateway', 'offline')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment-offline');
    }

    public function test_switching_to_offline_drops_an_intent_whose_gateway_is_no_longer_configured()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        // Started with a card gateway the site has since renamed, so the checkout cannot void it.
        $this->reservation->update(['payment_id' => 'pi_old', 'payment_gateway' => 'card', 'payment_intent_amount' => '100.00']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('selectedGateway', 'offline');

        // A late success on that intent no longer finds the booking it would confirm.
        $this->assertNull(Reservation::findByPaymentId('pi_old')->first());
        $this->assertSame('offline', $this->reservation->fresh()->payment_gateway);
    }

    public function test_switching_offline_method_leaves_a_booking_placed_meanwhile_as_it_was_placed()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class, 'label' => 'Credit Card'],
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
            'cash' => ['class' => OfflinePaymentGateway::class, 'label' => 'Cash', 'surcharge' => ['type' => 'fixed', 'amount' => 5]],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_id' => 'pi_card', 'payment_gateway' => 'fake', 'payment_intent_amount' => '100.00']);
        session(['resrv_reservation' => $this->reservation->id]);

        // Another tab places the booking with bank transfer while this one voids the card payment.
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->onRetrievePaymentIntent = function () use ($gateway) {
            $gateway->onRetrievePaymentIntent = null;
            DB::table('resrv_reservations')->where('id', $this->reservation->id)
                ->update(['status' => 'awaiting_payment', 'payment_gateway' => 'offline', 'payment_surcharge' => 0]);
        };

        $checkout = Livewire::test(Checkout::class)->call('handleSecondStep');

        try {
            $checkout->dispatch('gateway-selected', gateway: 'cash');
        } catch (ViewException) {
            // The checkout's re-render refuses the now-placed booking; what was stored is what matters.
        }

        $fresh = $this->reservation->fresh();
        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $fresh->status);
        $this->assertSame('offline', $fresh->payment_gateway);
        $this->assertTrue($fresh->payment_surcharge->isZero());
    }

    public function test_an_offline_form_left_open_cannot_place_the_booking_after_changing_payment_method()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->dispatch('gateway-selected', gateway: 'offline')
            ->assertSet('selectedGateway', 'offline')
            ->call('resetPaymentState')
            ->assertSet('selectedGateway', '');

        // The offline step still open in another tab.
        Livewire::test(CheckoutPayment::class)
            ->call('confirmPayment')
            ->assertHasErrors('reservation');

        $this->assertSame(ReservationStatus::PENDING->value, $this->reservation->fresh()->status);
    }

    public function test_confirm_payment_places_the_booking_awaiting_payment_without_confirming_it()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        // Nobody has paid yet: the booking waits for its payment, with no deadline.
        $reservation = $this->reservation->fresh();
        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->status);
        $this->assertNull($reservation->hold_expires_at);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_confirm_payment_redirects_to_checkout_completed()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        $checkoutEntry = \Statamic\Facades\Entry::query()->where('slug', 'checkout')->first();

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect($checkoutEntry->absoluteUrl().'?payment_pending='.$this->reservation->id);
    }

    public function test_confirm_payment_prevents_placing_the_booking_twice()
    {
        Mail::fake();

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['status' => 'awaiting_payment', 'payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => '',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        $this->app->terminate();

        // The first submit already sent the payment request and told the admins.
        Mail::assertNothingSent();
    }

    public function test_confirm_payment_rejects_null_payment_gateway()
    {
        $this->reservation->update(['payment_gateway' => null]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_rejects_non_offline_gateway()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'fake']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'test',
            'publicKey' => 'test',
            'amount' => 100.00,
            'paymentView' => '',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_works_with_custom_offline_gateway_key()
    {

        Config::set('resrv-config.payment_gateways', [
            'bank_transfer' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'bank_transfer']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => '',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $this->reservation->fresh()->status);
    }

    public function test_confirm_payment_rejects_reservation_with_expired_status_flag()
    {
        // Isolate the status-flag guard: reservation is within the hold window but status=expired.
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'created_at' => now(),
            'status' => 'expired',
            'payment_gateway' => 'offline',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_rejects_reservation_past_minutes_to_hold()
    {
        // Isolate the time-based guard: status=pending but created_at past minutes_to_hold.
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'created_at' => now()->subHours(2),
            'status' => 'pending',
            'payment_gateway' => 'offline',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_surfaces_error_when_reservation_expires_during_the_transition_race()
    {
        // Race: the row clears the PENDING pre-checks, then expires before transitionTo() locks it —
        // confirmPayment() must surface the expired error, not redirect as if it had been received.
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'created_at' => now(),
            'status' => 'pending',
            'payment_gateway' => 'offline',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Expire the row the first time it's hydrated as PENDING, before transitionTo() re-reads it.
        $reservationId = $this->reservation->id;
        Reservation::retrieved(function (Reservation $model) use ($reservationId) {
            if ((int) $model->id === $reservationId && $model->status === ReservationStatus::PENDING->value) {
                DB::table('resrv_reservations')
                    ->where('id', $reservationId)
                    ->update(['status' => ReservationStatus::EXPIRED->value]);
            }
        });

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation')
            ->assertNoRedirect();

        $this->assertEquals('expired', $this->reservation->fresh()->status);
        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_offline_refund_succeeds_in_cp()
    {
        $this->signInAdmin();

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'payment_gateway' => 'offline',
            'status' => 'confirmed',
        ]);

        $response = $this->patch(cp_route('resrv.reservation.refund'), [
            'id' => $this->reservation->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'refunded',
        ]);
    }

    public function test_offline_gateway_payment_gateway_saved_on_reservation()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_gateway' => 'offline',
        ]);
    }

    // --- An offline checkout booking: placed, held, then marked as paid or cancelled in the CP ---

    public function test_an_offline_checkout_booking_awaits_payment_with_its_dates_held()
    {
        Mail::fake();
        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: ACME\nIBAN: GR12 3456");

        $reservation = $this->placeOfflineCheckoutBooking();

        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->status);
        $this->assertNull($reservation->hold_expires_at);
        $this->assertSame('', $reservation->payment_id);
        $this->assertSame(1, $this->availableTomorrow());

        // The customer gets the payment request: thanked for a booking they made, with how to pay
        // and the reference to quote. The admins hear of a booking that waits for its payment.
        Mail::assertSent(ReservationPaymentRequest::class, function (ReservationPaymentRequest $mail) use ($reservation) {
            $html = $mail->render();

            return $mail->hasTo('guest@example.com')
                && str_contains($html, 'Thank you for your reservation! We have received it and are holding it for you until your payment arrives.')
                && str_contains($html, "Bank: ACME<br>\nIBAN: GR12 3456")
                && str_contains($html, 'Please include the booking reference '.$reservation->reference.' with your payment.');
        });
        Mail::assertSent(ReservationAwaitingPayment::class, fn ($mail) => $mail->hasTo('admin@example.com')
            && str_contains($mail->render(), 'A new reservation is awaiting payment.'));
        Mail::assertNotSent(ReservationConfirmedMail::class);
        Mail::assertNotSent(ReservationMade::class);
        $this->assertNotNull($reservation->payment_request_email_sent_at);

        // Long after the checkout hold, neither the expiry nor the lapse sweep releases the dates.
        $this->travel(2)->days();
        (new ExpireReservations(false))->handle();
        $this->artisan('resrv:cancel-lapsed-holds')->assertSuccessful();

        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->fresh()->status);
        $this->assertSame(1, $this->availableTomorrow());
    }

    public function test_the_admins_hear_of_an_offline_booking_when_its_payment_request_fails_to_send()
    {
        Mail::fake();
        $this->mock(ManualReservationCreator::class)
            ->shouldReceive('sendPaymentRequestEmail')->atLeast()->once()->andThrow(new \RuntimeException('Mail transport down'));

        $reservation = $this->placeOfflineCheckoutBooking();

        $this->assertSame(ReservationStatus::AWAITING_PAYMENT->value, $reservation->status);
        Mail::assertSent(ReservationAwaitingPayment::class, fn ($mail) => $mail->hasTo('admin@example.com'));
    }

    public function test_an_offline_parent_bookings_emails_list_each_stay()
    {
        Config::set('resrv-config.maximum_quantity', 3);
        $parent = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'type' => 'parent',
            'status' => 'awaiting_payment',
            'payment_gateway' => 'offline',
            'date_start' => '2026-12-01 00:00:00',
            'date_end' => '2026-12-10 00:00:00',
            'quantity' => 2,
        ]);
        ChildReservation::factory()->create(['reservation_id' => $parent->id, 'quantity' => 1, 'date_start' => '2026-12-01', 'date_end' => '2026-12-03']);
        ChildReservation::factory()->create(['reservation_id' => $parent->id, 'quantity' => 1, 'date_start' => '2026-12-07', 'date_end' => '2026-12-09']);

        foreach ([new ReservationPaymentRequest($parent), new ReservationAwaitingPayment($parent)] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('01-12-2026 00:00', $html);
            $this->assertStringContainsString('03-12-2026 00:00', $html);
            $this->assertStringContainsString('07-12-2026 00:00', $html);
            $this->assertStringContainsString('09-12-2026 00:00', $html);
            // The parent's own dates and quantity span both stays, so they don't describe either.
            $this->assertStringNotContainsString('10-12-2026', $html);
            $this->assertStringNotContainsString('x 2', $html);
        }
    }

    public function test_a_legacy_offline_confirmation_does_not_claim_the_payment_arrived()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment' => '50.00',
            'payment_surcharge' => '5.00',
            'payment_gateway' => 'offline',
            'payment_settlement' => 'unresolved',
        ]);

        $html = (new ReservationConfirmedMail($reservation))->render();

        $this->assertStringNotContainsString('Amount already paid', $html);
        $this->assertStringNotContainsString('Total charged', $html);
        $this->assertStringContainsString('Payment including surcharge', $html);
    }

    public function test_mark_as_paid_confirms_an_offline_checkout_booking()
    {
        $reservation = $this->placeOfflineCheckoutBooking();

        Mail::fake();
        $this->signInAdmin();

        $this->postJson(cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]))
            ->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('amount_paid_formatted', '100.00');

        $this->app->terminate();

        Mail::assertSent(ReservationConfirmedMail::class, fn ($mail) => $mail->hasTo('guest@example.com'));
        Mail::assertSent(ReservationMade::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        $this->assertSame(1, $this->availableTomorrow());
    }

    public function test_cancelling_an_unpaid_offline_checkout_booking_frees_its_dates()
    {
        $reservation = $this->placeOfflineCheckoutBooking();

        Mail::fake();
        $this->signInAdmin();

        $this->postJson(cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->app->terminate();

        $this->assertSame(2, $this->availableTomorrow());
        Mail::assertSent(ReservationCancelledCustomer::class, fn ($mail) => $mail->hasTo('guest@example.com'));
    }

    /** Book the two-available entry for two nights from tomorrow and confirm it at the offline step. */
    protected function placeOfflineCheckoutBooking(): Reservation
    {
        Config::set('resrv-config.admin_email', 'admin@example.com');
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $entry = $this->entries->get('two-available');

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ]);

        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])->call('checkout');

        $reservation = Reservation::findOrFail(session('resrv_reservation'));
        $reservation->update(['customer_id' => Customer::factory()->create(['email' => 'guest@example.com'])->id]);
        $this->assertSame(1, $this->availableTomorrow());

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment-offline');

        Livewire::test(CheckoutPayment::class)
            ->call('confirmPayment')
            ->assertHasNoErrors()
            ->assertRedirect();

        // The emails go out after the response.
        $this->app->terminate();

        return $reservation->fresh();
    }

    protected function availableTomorrow(): int
    {
        return (int) Availability::where('statamic_id', $this->entries->get('two-available')->id())
            ->whereDate('date', $this->date)
            ->value('available');
    }
}

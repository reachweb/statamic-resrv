<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Jobs\SendCancelledReservationEmails as SendCancelledReservationEmailsJob;
use Reach\StatamicResrv\Listeners\SendCancelledReservationEmails as SendCancelledReservationEmailsListener;
use Reach\StatamicResrv\Livewire\ReservationStatus;
use Reach\StatamicResrv\Mail\ReservationCancelled as ReservationCancelledMail;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationStatusTest extends TestCase
{
    protected function makeReservation(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'status' => 'confirmed',
            'item_id' => $item->id(),
            'payment_id' => 'pi_123',
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 2,
        ], $attributes));
    }

    public function test_renders_the_lookup_form()
    {
        Livewire::test(ReservationStatus::class)
            ->assertViewIs('statamic-resrv::livewire.reservation-status')
            ->assertSee(trans('statamic-resrv::frontend.findYourReservation'))
            ->assertStatus(200);
    }

    public function test_finds_reservation_by_email_and_reference()
    {
        $reservation = $this->makeReservation();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSet('reservationId', $reservation->id)
            ->assertSee($reservation->reference)
            ->assertSee(trans('statamic-resrv::frontend.statusConfirmed'));
    }

    public function test_lookup_is_case_insensitive()
    {
        $reservation = $this->makeReservation();
        $reservation->customer->update(['email' => 'someone@example.com']);

        Livewire::test(ReservationStatus::class)
            ->set('email', 'SomeOne@Example.com')
            ->set('reference', strtolower($reservation->reference))
            ->call('lookup')
            ->assertSet('reservationId', $reservation->id);
    }

    public function test_lookup_fails_with_wrong_email()
    {
        $reservation = $this->makeReservation();

        Livewire::test(ReservationStatus::class)
            ->set('email', 'not-the-customer@example.com')
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.reservationNotFound'));
    }

    public function test_lookup_does_not_find_pending_or_expired_reservations()
    {
        $pending = $this->makeReservation(['status' => 'pending']);

        Livewire::test(ReservationStatus::class)
            ->set('email', $pending->customer->email)
            ->set('reference', $pending->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null);

        $expired = $this->makeReservation(['status' => 'expired', 'reference' => 'EXPIRD']);

        Livewire::test(ReservationStatus::class)
            ->set('email', $expired->customer->email)
            ->set('reference', $expired->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null);
    }

    public function test_lookup_is_rate_limited_after_too_many_failed_attempts()
    {
        $reservation = $this->makeReservation();

        $component = Livewire::test(ReservationStatus::class)
            ->set('email', 'wrong@example.com')
            ->set('reference', $reservation->reference);

        foreach (range(1, 10) as $attempt) {
            $component->call('lookup')->assertSee(trans('statamic-resrv::frontend.reservationNotFound'));
        }

        // Even the correct credentials are rejected while the limiter is exhausted.
        $component
            ->set('email', $reservation->customer->email)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));
    }

    public function test_successful_lookup_does_not_reset_the_rate_limiter()
    {
        $reservation = $this->makeReservation();

        // Nine failures, then a success — if the success cleared the bucket, an attacker
        // holding one valid booking could reset the IP-wide budget indefinitely.
        foreach (range(1, 9) as $attempt) {
            Livewire::test(ReservationStatus::class)
                ->set('email', 'wrong@example.com')
                ->set('reference', $reservation->reference)
                ->call('lookup')
                ->assertHasErrors(['lookup']);
        }

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSet('reservationId', $reservation->id);

        // One more failure exhausts the original budget...
        Livewire::test(ReservationStatus::class)
            ->set('email', 'wrong@example.com')
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup']);

        // ...so even valid credentials are rejected now.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));
    }

    public function test_lookup_disambiguates_reservations_sharing_a_reference()
    {
        $first = $this->makeReservation();
        $second = $this->makeReservation();
        $first->customer->update(['email' => 'first@example.com']);
        $second->customer->update(['email' => 'second@example.com']);

        // The factory gives both rows the same reference — the collision scenario the
        // non-unique column allows.
        $this->assertSame($first->reference, $second->reference);

        Livewire::test(ReservationStatus::class)
            ->set('email', 'second@example.com')
            ->set('reference', $second->reference)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSet('reservationId', $second->id);

        Livewire::test(ReservationStatus::class)
            ->set('email', 'first@example.com')
            ->set('reference', $first->reference)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSet('reservationId', $first->id);
    }

    public function test_deep_link_disambiguates_reservations_sharing_a_reference()
    {
        $first = $this->makeReservation();
        $second = $this->makeReservation();
        $first->customer->update(['email' => 'first@example.com']);
        $second->customer->update(['email' => 'second@example.com']);

        $hash = hash_hmac('sha256', 'second@example.com', config('app.key'));

        Livewire::withQueryParams(['ref' => $second->reference, 'hash' => $hash])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', $second->id);
    }

    public function test_deep_link_loads_reservation_with_valid_hash()
    {
        $reservation = $this->makeReservation();
        $hash = hash_hmac('sha256', $reservation->customer->email, config('app.key'));

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $hash])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', $reservation->id)
            ->assertSee($reservation->reference);
    }

    public function test_deep_link_is_ignored_with_an_invalid_hash()
    {
        $reservation = $this->makeReservation();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('0', 64)])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.findYourReservation'));
    }

    public function test_deep_link_shares_the_lookup_rate_limit()
    {
        $reservation = $this->makeReservation();

        foreach (range(1, 10) as $attempt) {
            Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('0', 64)])
                ->test(ReservationStatus::class)
                ->assertSet('reservationId', null);
        }

        // Once the shared limiter is exhausted, even a valid deep link is ignored...
        $hash = hash_hmac('sha256', $reservation->customer->email, config('app.key'));

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $hash])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', null);

        // ...and so is the lookup form, since failed deep links drew from the same budget.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));
    }

    public function test_shows_cancel_button_within_the_free_cancellation_window()
    {
        $reservation = $this->makeReservation();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'));
    }

    public function test_hides_cancel_button_for_non_refundable_reservations()
    {
        $reservation = $this->makeReservation([
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.nonRefundable'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'));
    }

    public function test_hides_cancel_button_when_the_window_has_passed()
    {
        $reservation = $this->makeReservation([
            'date_start' => now()->addDay()->setTime(12, 0),
            'date_end' => now()->addDays(3)->setTime(12, 0),
            'free_cancellation_period' => 5,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertSee(trans('statamic-resrv::frontend.freeCancellationExpired'));
    }

    public function test_hides_cancel_button_when_no_cancellation_period_is_configured()
    {
        Config::set('resrv-config.free_cancellation_period', 0);

        $reservation = $this->makeReservation([
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'));
    }

    public function test_cancel_refunds_through_the_gateway_and_dispatches_events()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation();

        $this->mockRefundGateway();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'))
            ->assertSee(trans('statamic-resrv::frontend.statusCancelled'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);

        Event::assertDispatched(ReservationRefunded::class, fn ($event) => $event->reservation->id === $reservation->id);
        Event::assertDispatched(ReservationCancelledByCustomer::class, fn ($event) => $event->reservation->id === $reservation->id);
    }

    public function test_cancel_is_rejected_server_side_when_not_allowed()
    {
        $reservation = $this->makeReservation([
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_cancel_shows_generic_error_when_the_gateway_refund_fails()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation();

        $this->mockRefundGateway(new RefundFailedException('No such payment intent.'));

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationFailed'))
            ->assertDontSee('No such payment intent.');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);

        Event::assertNotDispatched(ReservationRefunded::class);
        Event::assertNotDispatched(ReservationCancelledByCustomer::class);
    }

    public function test_cancel_shows_generic_error_when_the_gateway_throws_unexpectedly()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation();

        // Not a RefundFailedException — e.g. a Stripe SDK connection/auth error that
        // escaped the gateway's own mapping. Must not surface as a 500.
        $this->mockRefundGateway(new \RuntimeException('Could not connect to Stripe.'));

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationFailed'))
            ->assertDontSee('Could not connect to Stripe.');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);

        Event::assertNotDispatched(ReservationRefunded::class);
        Event::assertNotDispatched(ReservationCancelledByCustomer::class);
    }

    public function test_offline_payment_reservations_cannot_be_cancelled_online()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = $this->makeReservation([
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_test_intent',
        ]);

        // The offline gateway's refund() is a no-op — auto-cancelling would mark the
        // booking refunded and email the customer that money moved when it never did.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_cancel_reports_success_even_when_a_post_refund_listener_fails()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = $this->makeReservation();

        $this->mockRefundGateway();

        // A synchronous listener exploding AFTER the refund committed (availability
        // restore, emails) must not read as a failed cancellation — the money already
        // moved and retrying could not rerun the listener anyway.
        Event::listen(ReservationRefunded::class, function (): void {
            throw new \RuntimeException('Availability restore exploded.');
        });

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
    }

    public function test_partner_reservations_cancel_without_claiming_a_refund()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation([
            'status' => 'partner',
            'payment_id' => '',
            'payment' => 100,
        ]);

        // No charge ever reached a gateway, so cancellation must neither call one...
        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoPaymentDescription'))
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            // ...nor tell the customer their money was returned.
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledNoPaymentSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.statusCancelled'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);

        Event::assertDispatched(ReservationRefunded::class);
        Event::assertDispatched(ReservationCancelledByCustomer::class);
    }

    public function test_cancelled_email_omits_the_refund_line_when_no_payment_was_collected()
    {
        $noCharge = $this->makeReservation([
            'status' => 'refunded',
            'payment_id' => '',
            'payment' => 100,
        ]);

        $html = (new ReservationCancelledMail($noCharge))->render();
        $this->assertStringNotContainsString('Refunded to the customer', $html);

        $paid = $this->makeReservation(['status' => 'refunded']);

        $html = (new ReservationCancelledMail($paid))->render();
        $this->assertStringContainsString('Refunded to the customer', $html);
    }

    public function test_customer_cancellation_event_is_wired_to_the_email_listener()
    {
        Event::fake();

        Event::assertListening(
            ReservationCancelledByCustomer::class,
            SendCancelledReservationEmailsListener::class
        );
    }

    public function test_cancelled_email_job_notifies_the_admin_but_not_the_customer()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = $this->makeReservation();

        (new SendCancelledReservationEmailsJob($reservation))->handle();

        Mail::assertSent(ReservationCancelledMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        Mail::assertNotSent(ReservationCancelledMail::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
    }

    public function test_displays_parent_reservation_children()
    {
        $reservation = $this->makeReservation(['type' => 'parent']);

        ChildReservation::create([
            'reservation_id' => $reservation->id,
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(11)->setTime(12, 0),
            'quantity' => 2,
            'price' => 100,
        ]);

        ChildReservation::create([
            'reservation_id' => $reservation->id,
            'date_start' => now()->addDays(11)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
            'quantity' => 1,
            'price' => 100,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(now()->addDays(10)->format('d-m-Y'))
            ->assertSee(now()->addDays(11)->format('d-m-Y'))
            ->assertSee('x2');
    }

    public function test_refunded_reservation_shows_cancelled_status_without_cancel_button()
    {
        $reservation = $this->makeReservation(['status' => 'refunded']);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSet('reservationId', $reservation->id)
            ->assertSee(trans('statamic-resrv::frontend.statusCancelled'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'));
    }
}

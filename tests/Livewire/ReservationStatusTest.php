<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\ReservationCancelled as ReservationCancelledEvent;
use Reach\StatamicResrv\Events\ReservationCancelledByCustomer;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Jobs\SendCancelledReservationEmails as SendCancelledReservationEmailsJob;
use Reach\StatamicResrv\Jobs\SendCustomerCancelledEmail as SendCustomerCancelledEmailJob;
use Reach\StatamicResrv\Listeners\SendCancelledReservationEmails as SendCancelledReservationEmailsListener;
use Reach\StatamicResrv\Listeners\SendCustomerCancelledEmail as SendCustomerCancelledEmailListener;
use Reach\StatamicResrv\Livewire\ReservationStatus;
use Reach\StatamicResrv\Mail\ReservationCancelled as ReservationCancelledMail;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer as ReservationCancelledCustomerMail;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Mail\ReservationRefunded as ReservationRefundedMail;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Entry;

class ReservationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The customer status feature is off by default; every test in this file exercises
        // it enabled unless it explicitly turns a toggle back off.
        Config::set('resrv-config.enable_reservation_status_page', true);
        Config::set('resrv-config.enable_customer_cancellations', true);
    }

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

        // Correct credentials are still rejected while the limiter is exhausted.
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

        // A success must not clear the bucket, or one valid booking could reset the budget indefinitely.
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

        // One more failure exhausts the original budget.
        Livewire::test(ReservationStatus::class)
            ->set('email', 'wrong@example.com')
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup']);

        // Valid credentials are now rejected.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));
    }

    public function test_rate_limiter_is_scoped_per_reference_so_one_booking_does_not_lock_out_another()
    {
        $first = $this->makeReservation(['reference' => 'AAAAAA']);
        $second = $this->makeReservation(['reference' => 'BBBBBB']);
        $first->customer->update(['email' => 'first@example.com']);
        $second->customer->update(['email' => 'second@example.com']);

        // Exhaust the budget for the first reference.
        foreach (range(1, 10) as $attempt) {
            Livewire::test(ReservationStatus::class)
                ->set('email', 'wrong@example.com')
                ->set('reference', 'AAAAAA')
                ->call('lookup')
                ->assertHasErrors(['lookup']);
        }

        // First reference is locked out even with correct credentials.
        Livewire::test(ReservationStatus::class)
            ->set('email', 'first@example.com')
            ->set('reference', 'AAAAAA')
            ->call('lookup')
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));

        // A different reference keeps its own budget, so an unrelated visitor on the same IP isn't blocked.
        Livewire::test(ReservationStatus::class)
            ->set('email', 'second@example.com')
            ->set('reference', 'BBBBBB')
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSet('reservationId', $second->id);
    }

    public function test_varying_the_reference_cannot_bypass_the_ip_wide_lookup_limit()
    {
        $reservation = $this->makeReservation();

        // Each distinct reference gets a fresh per-(IP, reference) bucket, so without an
        // IP-wide cap an attacker could enumerate references indefinitely from one address.
        foreach (range(1, 30) as $attempt) {
            Livewire::test(ReservationStatus::class)
                ->set('email', 'wrong@example.com')
                ->set('reference', 'GUESS'.$attempt)
                ->call('lookup')
                ->assertSee(trans('statamic-resrv::frontend.reservationNotFound'));
        }

        // A fresh reference — even with valid credentials — is now rejected by the IP bucket.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSet('reservationId', null)
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));

        // Deep links share the same budget, so mount() can't be used to keep guessing either.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('a', 64)])
            ->test(ReservationStatus::class)
            ->assertSet('linkFailed', true)
            ->assertSet('reservationId', null);
    }

    public function test_lookup_disambiguates_reservations_sharing_a_reference()
    {
        $first = $this->makeReservation();
        $second = $this->makeReservation();
        $first->customer->update(['email' => 'first@example.com']);
        $second->customer->update(['email' => 'second@example.com']);

        // The reference column is non-unique, so both rows can share one.
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

        $hash = $second->customerLookupHash();

        Livewire::withQueryParams(['ref' => $second->reference, 'hash' => $hash])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', $second->id);
    }

    public function test_deep_link_loads_reservation_with_valid_hash()
    {
        $reservation = $this->makeReservation();
        $hash = $reservation->customerLookupHash();

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

    public function test_deep_link_failure_shows_a_neutral_notice()
    {
        $reservation = $this->makeReservation();

        // A truncated/forged hash must not silently drop the customer onto a bare form with no
        // explanation; show a neutral notice that never reveals which failure occurred.
        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => str_repeat('0', 64)])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', null)
            ->assertSet('linkFailed', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationLinkFailed'))
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

        // Failed deep links and the lookup form share one budget: a valid deep link is now ignored.
        $hash = $reservation->customerLookupHash();

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $hash])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', null);

        // The lookup form is blocked too.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasErrors(['lookup'])
            ->assertSee(trans('statamic-resrv::frontend.tooManyLookupAttempts'));
    }

    public function test_a_cancelled_unpaid_hold_never_reads_as_a_retained_payment()
    {
        Mail::fake();

        // A manual reservation whose customer opened the pay link but never paid, cancelled by
        // the lapse sweep. The voided intent's reference is cleared on cancellation, so the
        // status page must show the plain cancelled label — never "no refund issued", which
        // claims a payment was retained.
        $reservation = $this->makeReservation([
            'status' => 'awaiting_payment',
            'payment_id' => 'pi_unpaid_hold',
            'payment_gateway' => 'fake',
        ]);

        app(ReservationRefundProcessor::class)->cancelWithoutRefund(
            $reservation,
            ReservationCancelledEvent::CONTEXT_HOLD_LAPSED,
            cancelOpenIntent: true,
        );

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSee(trans('statamic-resrv::frontend.statusCancelledNoRefund'))
            ->assertDontSee(trans('statamic-resrv::frontend.statusCancelledNoRefundIssued'));

        $this->assertTrue($reservation->fresh()->amountPaidOnline()->isZero());
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

    public function test_non_refundable_reservations_offer_a_cancel_without_refund()
    {
        $reservation = $this->makeReservation([
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        // The policy never had a window, so the wording must say "non-refundable" —
        // not "the free cancellation period has expired".
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.nonRefundable'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoRefundWarning'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNonRefundableDescription'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationDescription'));
    }

    public function test_expired_window_offers_a_cancel_without_refund()
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
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationDescription'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoRefundWarning'))
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoRefundDescription'));
    }

    public function test_cancel_after_the_window_keeps_the_payment_and_lands_in_cancelled()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledEvent::class, ReservationCancelledByCustomer::class]);

        // date_start +1 day with a 5-day free-cancellation period puts the deadline in the past.
        $reservation = $this->makeReservation([
            'date_start' => now()->addDay()->setTime(12, 0),
            'date_end' => now()->addDays(3)->setTime(12, 0),
            'free_cancellation_period' => 5,
        ]);

        // The payment stays with the business, so the gateway must never see a refund call.
        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledNoRefundSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);

        Event::assertNotDispatched(ReservationRefunded::class);
        Event::assertDispatched(ReservationCancelledEvent::class);
        Event::assertDispatched(ReservationCancelledByCustomer::class);
    }

    public function test_cancel_is_rejected_server_side_once_the_stay_has_started()
    {
        // Live booking whose date_start already passed: neither cancel path applies, and the
        // button being hidden is not the enforcement — a stale/malicious client can still
        // POST the cancel action.
        $reservation = $this->makeReservation([
            'date_start' => now()->subDay()->setTime(12, 0),
            'date_end' => now()->addDay()->setTime(12, 0),
            'free_cancellation_period' => 5,
        ]);

        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_cancel_is_allowed_on_the_last_day_of_the_free_cancellation_window()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        // deadline = date_start->startOfDay()->subDays(period). With date_start two days out and a
        // 2-day period the deadline is the start of today, so cancelling must stay allowed through
        // end of today (the inclusive-through-end-of-day contract).
        $reservation = $this->makeReservation([
            'date_start' => now()->addDays(2)->setTime(12, 0),
            'date_end' => now()->addDays(4)->setTime(12, 0),
            'free_cancellation_period' => 2,
        ]);

        $this->mockRefundGateway();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
    }

    public function test_hides_cancel_button_when_no_cancellation_period_is_configured()
    {
        Config::set('resrv-config.free_cancellation_period', 0);

        // Paid booking, no policy ever configured (NULL period): nothing was advertised at
        // checkout, so neither cancel path — especially not the no-refund one, which would
        // silently forfeit the payment — may be offered, and the server must reject a direct
        // cancel call too.
        $reservation = $this->makeReservation([
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
        ]);

        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefundWarning'))
            ->assertDontSee(trans('statamic-resrv::frontend.freeCancellationExpired'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
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

    public function test_non_refundable_reservations_cancel_without_a_refund()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledEvent::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation([
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        // Non-refundable means the money stays put — the gateway must never see a refund call.
        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledNoRefundSuccess'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);

        Event::assertNotDispatched(ReservationRefunded::class);
        Event::assertDispatched(ReservationCancelledEvent::class);
        Event::assertDispatched(ReservationCancelledByCustomer::class);
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

        // An unexpected (non-RefundFailedException) gateway error must not surface as a 500.
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

    public function test_cancel_retry_after_a_transient_failure_ends_in_a_clean_success_state()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation();

        // The first refund fails transiently; the retry succeeds.
        $gateway = \Mockery::mock(FakePaymentGateway::class)->makePartial();
        $attempt = 0;
        $gateway->shouldReceive('refund')->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new RefundFailedException('transient gateway error');
            }

            return true;
        });
        $manager = \Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')->andReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $component = Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationFailed'));

        // The retry succeeds: no stale "something went wrong" error beside the success notice.
        $component->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancellationFailed'));
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

        // Offline gateway refund() is a no-op, so cancelling would falsely tell the customer money moved.
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

    public function test_unknown_recorded_gateway_blocks_self_cancel_but_keeps_the_page_viewable()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        // The gateway this reservation paid through has since been removed from config —
        // nobody can move that money automatically, so the page must degrade to view-only
        // instead of routing the foreign payment_id to the current default gateway.
        $reservation = $this->makeReservation([
            'payment_gateway' => 'legacy-stripe',
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);

        // Display still works without the gateway: the recorded charge is reported
        // instead of throwing while asking the missing gateway how it collects.
        $this->assertSame(
            $reservation->amountPaid()->format(),
            $reservation->fresh()->amountPaidOnline()->format()
        );
    }

    public function test_cancel_reports_success_even_when_a_post_refund_listener_fails()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = $this->makeReservation();

        $this->mockRefundGateway();

        // A listener failing after the refund committed must not read as a failed cancellation.
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
        Event::fake([ReservationRefunded::class, ReservationCancelledEvent::class, ReservationCancelledByCustomer::class]);

        $reservation = $this->makeReservation([
            'status' => 'partner',
            'payment_id' => '',
            'payment' => 100,
        ]);

        // No charge reached a gateway, so cancellation must not call one or claim a refund.
        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.cancelReservationNoPaymentDescription'))
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledNoPaymentSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'))
            ->assertDontSee(trans('statamic-resrv::frontend.statusCancelled'));

        // Nothing was returned, so the terminal state is CANCELLED and the cancelled event
        // chain fires — REFUNDED and its event are reserved for money actually moving back.
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);

        Event::assertNotDispatched(ReservationRefunded::class);
        Event::assertDispatched(ReservationCancelledEvent::class);
        Event::assertDispatched(ReservationCancelledByCustomer::class);
    }

    public function test_amount_paid_reflects_the_actual_gateway_charge()
    {
        // Amount paid is payment + surcharge, charged in one intent.
        $charged = $this->makeReservation([
            'payment' => 50,
            'payment_surcharge' => 4.30,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $charged->customer->email)
            ->set('reference', $charged->reference)
            ->call('lookup')
            ->assertSee('54.30')
            ->assertDontSee('50.00');

        // Partner bookings hold a would-be deposit in `payment` but collected nothing.
        $partner = $this->makeReservation([
            'status' => 'partner',
            'payment_id' => '',
            'payment' => 123.45,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $partner->customer->email)
            ->set('reference', $partner->reference)
            ->call('lookup')
            ->assertDontSee('123.45');
    }

    public function test_refunded_email_subject_matches_whether_money_moved()
    {
        $refunded = $this->makeReservation(['status' => 'refunded']);
        (new ReservationRefundedMail($refunded))->assertHasSubject('Reservation Refunded');

        // No-payment branch says "cancelled", so the subject must not default to "Refunded".
        $noCharge = $this->makeReservation([
            'status' => 'refunded',
            'payment_id' => '',
            'payment' => 100,
        ]);
        (new ReservationRefundedMail($noCharge))->assertHasSubject('Reservation Cancelled');

        $configured = (new ReservationRefundedMail($noCharge))
            ->applyResrvEmailConfig(['subject' => 'Sorry to see you go']);
        $configured->assertHasSubject('Sorry to see you go');
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

    public function test_cancelled_email_reports_a_retained_payment_instead_of_a_refund()
    {
        // A no-refund customer cancellation lands in CANCELLED with the charge retained. The
        // gateway could refund it (refundIsAutomatic() is about capability), but no money
        // moved — the admin email must say so and must not ask for a manual refund either.
        $retained = $this->makeReservation(['status' => 'cancelled']);

        $html = (new ReservationCancelledMail($retained))->render();

        $this->assertStringContainsString('payment retained', $html);
        $this->assertStringNotContainsString('Refunded to the customer', $html);
        $this->assertStringNotContainsString('refund the customer manually', $html);

        // A cancelled no-charge booking (partner / zero payment) has nothing to report.
        $noCharge = $this->makeReservation([
            'status' => 'cancelled',
            'payment_id' => '',
        ]);

        $html = (new ReservationCancelledMail($noCharge))->render();

        $this->assertStringNotContainsString('Refund information', $html);
    }

    public function test_cancelled_email_asks_admins_to_refund_manually_for_offline_gateways()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = $this->makeReservation([
            'status' => 'refunded',
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_test_intent',
        ]);

        $html = (new ReservationCancelledMail($reservation))->render();

        $this->assertStringContainsString('refund the customer manually', $html);
        $this->assertStringNotContainsString('Refunded to the customer', $html);
    }

    public function test_lookup_hash_is_scoped_to_a_single_reservation()
    {
        // One customer, two confirmed bookings under the same email but different references.
        $customer = Customer::factory()->create(['email' => 'shared@example.com']);

        $first = $this->makeReservation(['reference' => 'BOOK01', 'customer_id' => $customer->id]);
        $second = $this->makeReservation(['reference' => 'BOOK02', 'customer_id' => $customer->id]);

        $statuses = ['confirmed', 'refunded'];

        // Each manage-link resolves only its own booking.
        $this->assertSame(
            $first->id,
            Reservation::findForCustomerLookup('BOOK01', $first->customerLookupHash(), $statuses)?->id
        );
        $this->assertSame(
            $second->id,
            Reservation::findForCustomerLookup('BOOK02', $second->customerLookupHash(), $statuses)?->id
        );

        // The hash is bound to the reservation, not just the email, so the two differ...
        $this->assertNotSame($first->customerLookupHash(), $second->customerLookupHash());

        // ...and the first booking's hash cannot be replayed against the second by swapping ref.
        $this->assertNull(
            Reservation::findForCustomerLookup('BOOK02', $first->customerLookupHash(), $statuses)
        );
    }

    public function test_offline_bookings_are_not_reported_as_paid_online()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = $this->makeReservation([
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_test_intent',
            'payment' => 50,
            'payment_surcharge' => 0,
        ]);

        // Nothing was collected online — the deposit arrives by bank transfer out-of-band.
        $this->assertSame('0.00', $reservation->amountPaidOnline()->format());

        // The recorded charge stays intact for the admin "refund manually" path.
        $this->assertSame('50.00', $reservation->amountPaid()->format());

        // The customer status page must not present that deposit as already paid.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.amountPaid'))
            ->assertDontSee('50.00');
    }

    public function test_customer_status_url_is_null_without_a_configured_status_entry()
    {
        $reservation = $this->makeReservation();

        $this->assertNull($reservation->customerStatusUrl());
    }

    public function test_customer_status_url_builds_an_authenticated_deep_link_when_configured()
    {
        $page = $this->makeStatamicItem();
        Config::set('resrv-config.reservation_status_entry', $page->id());

        $reservation = $this->makeReservation();
        $url = $reservation->customerStatusUrl();

        $this->assertNotNull($url);
        $this->assertStringContainsString('ref='.$reservation->reference, $url);
        $this->assertStringContainsString('hash='.$reservation->customerLookupHash(), $url);
    }

    public function test_customer_status_url_accepts_an_integer_entry_id()
    {
        // Eloquent-driver sites can store integer entry IDs, which the settings YAML
        // round-trips as a real int — the URL must still build.
        $this->ensureCollectionExists('pages');
        Entry::make()->collection('pages')->id('4242')->slug('status-page')->data(['title' => 'Status'])->save();

        Config::set('resrv-config.reservation_status_entry', 4242);

        $reservation = $this->makeReservation();
        $url = $reservation->customerStatusUrl();

        $this->assertNotNull($url);
        $this->assertStringContainsString('ref='.$reservation->reference, $url);
    }

    public function test_customer_status_url_is_null_for_an_unpublished_status_entry()
    {
        // url() ignores publish state, so the guard must — a draft status page would
        // email customers a link that 404s.
        $page = $this->makeStatamicItem();
        $page->published(false)->save();

        Config::set('resrv-config.reservation_status_entry', $page->id());

        $reservation = $this->makeReservation();

        $this->assertNull($reservation->customerStatusUrl());
    }

    public function test_confirmation_email_links_to_the_status_page_when_configured()
    {
        $page = $this->makeStatamicItem();
        Config::set('resrv-config.reservation_status_entry', $page->id());

        $reservation = $this->makeReservation();
        $html = (new ReservationConfirmedMail($reservation))->render();

        $this->assertStringContainsString('Manage your booking', $html);
        $this->assertStringContainsString('ref='.$reservation->reference, $html);
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

    public function test_cancelled_event_is_wired_to_the_customer_email_listener()
    {
        Event::fake();

        Event::assertListening(
            ReservationCancelledEvent::class,
            SendCustomerCancelledEmailListener::class
        );
    }

    public function test_customer_cancelled_email_job_notifies_the_customer_but_not_the_admin()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');

        $reservation = $this->makeReservation();

        (new SendCustomerCancelledEmailJob($reservation))->handle();

        Mail::assertSent(ReservationCancelledCustomerMail::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
        Mail::assertNotSent(ReservationCancelledCustomerMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
    }

    public function test_offline_reservations_stay_view_only_even_after_the_window_expires()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        // Expired window + offline gateway: the no-refund path is for online self-service
        // payments only — offline arrangements are "contact us" by design.
        $reservation = $this->makeReservation([
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_test_intent',
            'date_start' => now()->addDay()->setTime(12, 0),
            'date_end' => now()->addDays(3)->setTime(12, 0),
            'free_cancellation_period' => 5,
        ]);

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_partner_reservations_stay_view_only_after_the_window_expires()
    {
        // Partner bookings hold no gateway payment, so the no-refund path (which exists to
        // retain a payment) does not apply — outside the window they are "contact us" cases.
        $reservation = $this->makeReservation([
            'status' => 'partner',
            'payment_id' => '',
            'date_start' => now()->addDay()->setTime(12, 0),
            'date_end' => now()->addDays(3)->setTime(12, 0),
            'free_cancellation_period' => 5,
        ]);

        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'partner',
        ]);
    }

    public function test_period_zero_allows_a_full_refund_cancel_on_arrival_day()
    {
        Event::fake([ReservationRefunded::class, ReservationCancelledByCustomer::class]);

        // deadlineFor() = date_start->startOfDay()->subDays(period); with period 0 the deadline
        // is the start of arrival day, and the inclusive-through-end-of-day contract keeps the
        // refund cancel available ON the arrival day itself. This matches the "free cancellation
        // until <date>" label advertised at checkout — a deliberate, documented boundary.
        $reservation = $this->makeReservation([
            'date_start' => now()->endOfDay()->subMinutes(5),
            'date_end' => now()->addDays(2)->setTime(12, 0),
            'free_cancellation_period' => 0,
        ]);

        $this->mockRefundGateway();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSet('cancelled', true)
            ->assertSee(trans('statamic-resrv::frontend.reservationCancelledSuccess'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);

        Event::assertDispatched(ReservationRefunded::class);
    }

    public function test_period_zero_refund_is_blocked_after_a_timed_check_in_has_started()
    {
        // Freeze mid-afternoon so "after the 12:00 check-in, before midnight" is deterministic.
        $this->travelTo(now()->setTime(18, 0));

        // The zero-day window stays open through the end of the arrival day (deliberate,
        // pinned above), but this booking carries a real check-in time that has passed —
        // an already-started stay must not self-refund during the remaining evening hours.
        $reservation = $this->makeReservation([
            'date_start' => now()->setTime(12, 0),
            'date_end' => now()->addDays(2)->setTime(12, 0),
            'free_cancellation_period' => 0,
        ]);

        $this->forbidGatewayRefunds();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationDescription'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false)
            ->assertSee(trans('statamic-resrv::frontend.cancellationNotAllowed'));

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_disabled_status_page_renders_nothing_and_blocks_every_action()
    {
        Config::set('resrv-config.enable_reservation_status_page', false);

        $reservation = $this->makeReservation();

        // Hidden UI is not enforcement: the actions themselves must no-op. Valid credentials
        // and a valid deep link both resolve nothing while the feature is off.
        Livewire::test(ReservationStatus::class)
            ->assertDontSee(trans('statamic-resrv::frontend.findYourReservation'))
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSet('reservationId', null)
            ->call('cancel')
            ->assertSet('cancelled', false);

        Livewire::withQueryParams(['ref' => $reservation->reference, 'hash' => $reservation->customerLookupHash()])
            ->test(ReservationStatus::class)
            ->assertSet('reservationId', null);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_disabled_cancellations_keep_the_page_view_only()
    {
        Config::set('resrv-config.enable_customer_cancellations', false);

        $reservation = $this->makeReservation();

        // The page itself still works, but no cancel path is offered or accepted.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSet('reservationId', $reservation->id)
            ->assertSee($reservation->reference)
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservationNoRefund'))
            ->call('cancel')
            ->assertHasErrors(['cancellation'])
            ->assertSet('cancelled', false);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_customer_status_url_is_null_when_the_page_toggle_is_off()
    {
        Config::set('resrv-config.enable_reservation_status_page', false);

        $page = $this->makeStatamicItem();
        Config::set('resrv-config.reservation_status_entry', $page->id());

        $reservation = $this->makeReservation();

        // A configured entry alone must not produce email links while the feature is off.
        $this->assertNull($reservation->customerStatusUrl());
    }

    public function test_completed_reservations_are_viewable_with_their_own_label()
    {
        $reservation = $this->makeReservation(['status' => 'completed']);

        // A post-stay link visit must resolve — not claim the reservation doesn't exist —
        // and must not offer any cancel action.
        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSet('reservationId', $reservation->id)
            ->assertSee(trans('statamic-resrv::frontend.statusCompleted'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'))
            ->assertDontSee(trans('statamic-resrv::frontend.cancellationNotAllowed'));
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

    public function test_shows_option_values_deleted_after_booking()
    {
        // Option values are soft-deleted to preserve reservation history — the status page
        // must keep showing the value the customer originally booked.
        $reservation = $this->makeReservation();

        $option = Option::factory()->create(['item_id' => $reservation->item_id]);
        $value = OptionValue::factory()->create(['option_id' => $option->id]);
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $value->delete();

        Livewire::test(ReservationStatus::class)
            ->set('email', $reservation->customer->email)
            ->set('reference', $reservation->reference)
            ->call('lookup')
            ->assertSee($option->name)
            ->assertSee($value->name);
    }
}

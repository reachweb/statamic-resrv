<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry;

/**
 * The customer reservation-status page (/__t/status): lookup form, ?ref=&hash= deep
 * link, and self-service cancellation. tests/Livewire/ReservationStatusTest owns the
 * component logic (policy routing, rate limiting, hash security, refund vs no-refund);
 * this suite covers only what needs a real browser: the wire:confirm native dialog,
 * the deep link arriving through a real navigation's query string, and the
 * validation/error rendering of the wire:submit round-trip.
 *
 * Fixtures are written by the TEST process into the shared file SQLite (Gotcha #5) and
 * read back by the served app; customerLookupHash() agrees across the boundary because
 * both processes run on the same skeleton APP_KEY. Every fixture is a NO-CHARGE booking
 * (payment_id '' + payment 0): gatewayHoldsNoCharge() short-circuits
 * supportsAutomaticRefund(), so the offline-only served gateway never blocks the cancel
 * path and cancelling routes to CANCELLED without any gateway call.
 */
class ReservationStatusPageTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearLookupRateLimiter();
    }

    /**
     * The lookup rate limiter lives in the FILE cache store — shared by both processes
     * like the session files, and (unlike the truncated DB) it survives across tests and
     * across whole suite runs. The failed lookups the negative paths record here would
     * otherwise accumulate toward the 30-per-IP budget and start rate-limiting unrelated
     * tests re-run within the 10-minute decay, so reset the browser IP's bucket before
     * every test — and the per-reference bucket wherever a test hits a fixed reference.
     * Fixture references need no clearing: each is freshly random, so its key never repeats.
     */
    protected function clearLookupRateLimiter(?string $reference = null): void
    {
        RateLimiter::clear('resrv-status-lookup-ip:'.sha1('127.0.0.1'));

        if ($reference !== null) {
            RateLimiter::clear('resrv-status-lookup:'.sha1('127.0.0.1|'.$reference));
        }
    }

    /**
     * A confirmed, customer-cancellable booking on the seeded bookable entry: inside its
     * snapshotted 2-day free-cancellation window, dated within the seeded availability
     * window so the IncreaseAvailability listener finds rows to restore.
     */
    protected function createLookableReservation(array $attributes = []): Reservation
    {
        $entry = Entry::query()->where('collection', 'pages')->where('slug', 'bookable')->first();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'status' => ReservationStatus::CONFIRMED->value,
            'reference' => (new Reservation)->createRandomReference(),
            'item_id' => $entry->id(),
            'payment_id' => '',
            'payment' => 0,
            'date_start' => now()->addDays(10)->setTime(12, 0),
            'date_end' => now()->addDays(12)->setTime(12, 0),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 2,
        ], $attributes));
    }

    public function test_lookup_form_finds_the_reservation_and_start_over_returns_to_it(): void
    {
        $reservation = $this->createLookableReservation();

        $this->browse(function (Browser $browser) use ($reservation) {
            $browser->visit('/__t/status')->waitFor('#resrv-status-email')
                ->type('#resrv-status-email', $reservation->customer->email)
                ->type('#resrv-status-reference', $reservation->reference)
                ->click('[dusk=status-route] button[type=submit]')
                ->waitForText(trans('statamic-resrv::frontend.reservationStatus'))
                ->assertSee($reservation->reference)
                ->assertSee(trans('statamic-resrv::frontend.statusConfirmed'))
                ->assertSee('Bookable Room')
                ->assertSee(trans('statamic-resrv::frontend.cancelReservation'));

            $browser->click('[wire\\:click="startOver"]')
                ->waitFor('#resrv-status-email')
                ->assertDontSee($reservation->reference);
        });
    }

    public function test_deep_link_opens_the_reservation_and_a_bad_hash_falls_back_to_the_form(): void
    {
        $reservation = $this->createLookableReservation();

        $this->browse(function (Browser $browser) use ($reservation) {
            $browser->visit('/__t/status?ref='.$reservation->reference.'&hash='.$reservation->customerLookupHash())
                ->waitForText(trans('statamic-resrv::frontend.reservationStatus'))
                ->assertSee($reservation->reference)
                ->assertSee(trans('statamic-resrv::frontend.statusConfirmed'))
                ->assertDontSee(trans('statamic-resrv::frontend.findYourReservation'));

            // A well-formed but wrong hash surfaces the single neutral notice and the
            // lookup form — never the reservation, never the failure cause.
            $browser->visit('/__t/status?ref='.$reservation->reference.'&hash='.str_repeat('0', 64))
                ->waitForText(trans('statamic-resrv::frontend.reservationLinkFailed'))
                ->assertSee(trans('statamic-resrv::frontend.findYourReservation'))
                ->assertDontSee($reservation->reference);
        });
    }

    public function test_validation_and_not_found_errors_render(): void
    {
        $this->clearLookupRateLimiter('NOSUCH');

        $this->browse(function (Browser $browser) {
            $browser->visit('/__t/status')->waitFor('#resrv-status-email')
                ->click('[dusk=status-route] button[type=submit]')
                ->waitForText('The email field is required.')
                ->assertSee('The reference field is required.');

            $browser->type('#resrv-status-email', 'nobody@example.com')
                ->type('#resrv-status-reference', 'NOSUCH')
                ->click('[dusk=status-route] button[type=submit]')
                ->waitForText(trans('statamic-resrv::frontend.reservationNotFound'));
        });
    }

    public function test_cancelling_asks_for_confirmation_and_cancels_only_when_accepted(): void
    {
        $reservation = $this->createLookableReservation();

        $this->browse(function (Browser $browser) use ($reservation) {
            $browser->visit('/__t/status?ref='.$reservation->reference.'&hash='.$reservation->customerLookupHash())
                ->waitFor('[wire\\:click="cancel"]');

            // Dismissing the wire:confirm dialog must abort the action entirely.
            $browser->click('[wire\\:click="cancel"]')
                ->waitForDialog()
                ->assertDialogOpened(trans('statamic-resrv::frontend.cancelReservationConfirmation'))
                ->dismissDialog()
                ->pause(500)
                ->assertSee(trans('statamic-resrv::frontend.statusConfirmed'));

            $this->assertEquals(ReservationStatus::CONFIRMED->value, $reservation->fresh()->status);

            // Accepting it cancels: no-payment success message, badge flips to the
            // terminal label, and the cancel button is gone.
            $browser->click('[wire\\:click="cancel"]')
                ->waitForDialog()
                ->acceptDialog()
                ->waitForText(trans('statamic-resrv::frontend.reservationCancelledNoPaymentSuccess'))
                ->assertSee(trans('statamic-resrv::frontend.statusCancelledNoRefund'))
                ->assertDontSee(trans('statamic-resrv::frontend.cancelReservation'));

            $this->assertEquals(ReservationStatus::CANCELLED->value, $reservation->fresh()->status);
        });
    }
}

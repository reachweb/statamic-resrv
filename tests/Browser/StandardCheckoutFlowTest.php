<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Reservation;

/**
 * The headline end-to-end test: the whole standard checkout funnel driven through
 * a real browser on the Stripe-free offline gateway —
 *   search → results → extra + option → customer form → offline confirm → completed.
 *
 * The 328 headless Livewire tests own the per-step validation/pricing/state; this
 * proves the funnel actually renders and round-trips through Statamic's routing in
 * a real DOM and lands a CONFIRMED reservation with availability decremented. It is
 * the living documentation of the flow, so it reads top-to-bottom as one journey.
 */
class StandardCheckoutFlowTest extends BrowserTestCase
{
    public function test_a_standard_booking_completes_to_a_confirmed_reservation(): void
    {
        // The whole seed window is available before this booking decrements one night.
        $availableBefore = Availability::where('available', '>', 0)->count();

        $completedUrl = '';

        $this->browse(function (Browser $browser) use (&$completedUrl) {
            // 1. Search: open the calendar, pick an available day; live=true resolves
            //    the results so the Book Now action appears with no submit click.
            $browser->visit('/bookable')->waitFor('[name=datepicker]')
                ->click('[name=datepicker]')->waitFor('.rc-day__label')
                ->click('.rc-day--available')->waitFor('[wire\\:click="checkout()"]');

            // 2. Book Now creates the pending reservation and redirects to checkout step 1.
            $browser->click('[wire\\:click="checkout()"]')
                ->waitForLocation('/checkout')
                ->waitFor('[wire\\:click="handleFirstStep()"]');

            // 3. Add an extra and select an option. Both inputs are intentionally
            //    visually-hidden (sr-only checkbox / styled radio), so toggle them by
            //    dispatching a real click on their wrapping <label>. A short pause lets
            //    the wire:change.throttle round-trip reach the parent Checkout before we
            //    advance (otherwise the selection wouldn't be assigned to the reservation).
            $browser->script('document.querySelector("input[type=radio]").closest("label").click();');
            $browser->pause(800);
            $browser->script('document.querySelector("input[type=checkbox]").closest("label").click();');
            $browser->pause(800);

            // 4. Proceed to the customer form (step 2) and fill every required field.
            $browser->click('[wire\\:click="handleFirstStep()"]')
                ->waitFor('#first_name', 10)
                ->type('#first_name', 'Jane')
                ->type('#last_name', 'Doe')
                ->type('#email', 'jane@example.com')
                ->type('#repeat_email', 'jane@example.com');

            // 5. Submit → the lone offline gateway auto-selects → offline payment (step 3).
            $browser->click('[wire\\:click="submit()"]')
                ->waitFor('@confirm-payment', 10);

            // 6. Offline confirm → ReservationConfirmed → redirect to the completed page.
            $browser->click('@confirm-payment')
                ->waitForLocation('/checkout-completed', 10);

            $completedUrl = $browser->driver->getCurrentURL();
        });

        // --- Acceptance: completed URL + CONFIRMED row + decremented availability ---
        $reservation = Reservation::latest('id')->first();
        $this->assertNotNull($reservation, 'A reservation should have been created by the funnel.');

        // Completed page reached carrying ?payment_pending={id}.
        $this->assertStringContainsString(
            'payment_pending='.$reservation->id,
            $completedUrl,
            'The completed page URL must carry the reservation id as payment_pending.'
        );

        // The offline confirm transitioned the reservation to CONFIRMED.
        $this->assertSame(ReservationStatus::CONFIRMED->value, $reservation->status);

        // The extra + option chosen in step 1 were assigned to the reservation.
        $this->assertSame(1, $reservation->extras()->count());
        $this->assertSame(1, $reservation->options()->count());

        // DecreaseAvailability (a ReservationCreated listener) took the booked night
        // off inventory: the booked date's row is now zero and the overall available
        // count dropped by exactly that one night.
        $this->assertSame($availableBefore - 1, Availability::where('available', '>', 0)->count());
        $this->assertSame(
            0,
            (int) Availability::where('statamic_id', $reservation->item_id)
                ->whereDate('date', $reservation->date_start)
                ->value('available')
        );
    }
}

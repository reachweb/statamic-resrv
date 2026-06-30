<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry;

/**
 * AvailabilityMultiResults — the v6 cart-based multi-rate / multi-date component
 * (the replacement for the removed advanced/per-property availability). The 328
 * headless Livewire tests own the quantity/price/availability math; this drives the
 * real cart UI in a browser: per-rate quantity steppers, addSelections() building
 * multiple lines, removeSelection() updating the grand total reactively, and
 * checkout() producing one parent reservation with multiple child lines.
 *
 * Harness notes:
 * - The /multi page seeds TWO rates (SeedsBookableContent::ensureMultiEntry): the
 *   shared `default` rate plus an entry-scoped `children` rate, so the cart has more
 *   than one line to combine.
 * - The /multi search carries show-availability-on-calendar, but with two rates
 *   reconcileRate() can't auto-select one, so the calendar only paints once a rate
 *   is chosen — hence each flow selects a rate in the dropdown before opening the
 *   calendar. The multi-results cart still resolves ALL rates regardless (it queries
 *   rate_id='any'), so picking one rate to paint the calendar does not restrict the
 *   cart.
 * - Seed availability is 1 per night, so every line stays quantity=1; three lines
 *   are reached by adding the `default` rate twice (the documented "combine the same
 *   rate across searches" path), then the duplicate is removed before checkout so the
 *   surviving two distinct-rate lines validate at availability=1.
 */
class MultiRateCartTest extends BrowserTestCase
{
    public function test_multi_rate_cart_builds_three_lines_removes_one_and_checks_out(): void
    {
        [$entryId, $defaultRate, $childrenRate] = $this->multiRateFixtures();

        $this->browse(function (Browser $browser) use ($defaultRate, $childrenRate) {
            $this->openCartWithDates($browser, $defaultRate);

            // Round 1: one of each rate → two cart lines (indices 0 and 1).
            $this->stepRateToOne($browser, $defaultRate);
            $this->stepRateToOne($browser, $childrenRate);
            $browser->waitFor('[wire\\:click="addSelections"]')
                ->click('[wire\\:click="addSelections"]')
                ->waitFor('[wire\\:click="removeSelection(1)"]');

            // Round 2: the `default` rate again → a third line (index 2). Same rate
            // and dates as line 0, which aggregates at checkout — so it is the line
            // we remove before confirming.
            $this->stepRateToOne($browser, $defaultRate);
            $browser->waitFor('[wire\\:click="addSelections"]')
                ->click('[wire\\:click="addSelections"]')
                ->waitFor('[wire\\:click="removeSelection(2)"]');

            // Three lines, grand total = 3 × 50.00.
            $this->assertCount(3, $browser->elements('[wire\\:click^="removeSelection"]'));
            $browser->assertSeeIn('@multi-grand-total', '150.00');

            // Remove the duplicate line → two distinct-rate lines remain and the grand
            // total reverts reactively (no reload).
            $browser->click('[wire\\:click="removeSelection(2)"]')
                ->waitUntilMissing('[wire\\:click="removeSelection(2)"]');
            $this->assertCount(2, $browser->elements('[wire\\:click^="removeSelection"]'));
            $browser->assertSeeIn('@multi-grand-total', '100.00');

            // Checkout converts the cart into a single (parent) reservation and
            // redirects to the checkout entry, where step 1 renders for the parent.
            // Waiting for the step-1 control (not just the URL) both proves the parent
            // reservation reaches a working checkout and lets the mount finish before we
            // leave — navigating away mid-mount would abort the round-trip.
            $browser->click('[wire\\:click="checkout"]')
                ->waitForLocation('/checkout')
                ->waitFor('[wire\\:click="handleFirstStep()"]');

            // Leave the live Checkout page before this test ends. Its Checkout
            // component is bound to the session reservation; the next test's setUp
            // clears that session, so a lingering /checkout would 500 ("Reservation
            // not found in the session") on its next round-trip and poison the reused
            // browser. Returning to a neutral page keeps the suite order-independent.
            $browser->visit('/bookable')->waitFor('[name=datepicker]');
        });

        // --- Acceptance: one parent reservation with two child lines ---
        $parent = Reservation::where('item_id', $entryId)
            ->where('type', ReservationTypes::PARENT->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($parent, 'The cart checkout should have created a parent reservation.');
        $this->assertSame(2, (int) $parent->quantity, 'The two surviving lines total a quantity of 2.');

        $children = ChildReservation::where('reservation_id', $parent->id)->get();
        $this->assertCount(2, $children, 'Each surviving cart line becomes a child reservation.');
        $this->assertEqualsCanonicalizing(
            [$defaultRate, $childrenRate],
            $children->pluck('rate_id')->map(fn ($id) => (int) $id)->all(),
            'The child lines carry the two distinct rate ids from the cart.'
        );
    }

    public function test_checkout_with_an_empty_cart_renders_the_select_a_rate_error(): void
    {
        [, $defaultRate] = $this->multiRateFixtures();

        $this->browse(function (Browser $browser) use ($defaultRate) {
            // Dates are set (so the cart's error block can render) but no line is added.
            $this->openCartWithDates($browser, $defaultRate);

            // The cart's "Book Now" button only renders once a selection exists, so the
            // empty-cart guard cannot be reached by a click. Invoke checkout() directly
            // on the multi-results component (the same window.Livewire poke T13 uses).
            $browser->script(<<<'JS'
                const stepper = document.querySelector('[wire\\:click^="updateRateQuantity"]');
                const root = stepper.closest('[wire\\:id]');
                window.Livewire.find(root.getAttribute('wire:id')).call('checkout');
            JS);

            $browser->waitForText('Please select at least one rate.')
                ->assertSee('Please select at least one rate.');
        });
    }

    /**
     * Resolve the seeded multi entry id and its two rate ids from the shared DB.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function multiRateFixtures(): array
    {
        $entryId = Entry::query()
            ->where('collection', 'pages')
            ->where('slug', 'multi')
            ->first()
            ->id();

        // Lift the seed's availability=1 to a small surplus for THIS entry only (the
        // sanctioned per-test variant). The cart would otherwise book the last unit, and
        // the served checkout page re-prices a parent reservation by re-querying
        // availability per child line — which returns no usable row at 0 and 500s the
        // render. The bookable entry T13/T14 rely on keeps availability=1.
        Availability::where('statamic_id', $entryId)->update(['available' => 5]);

        $defaultRate = (int) Rate::where('collection', 'pages')->where('slug', 'default')->value('id');
        $childrenRate = (int) Rate::where('collection', 'pages')->where('slug', 'children')->value('id');

        return [$entryId, $defaultRate, $childrenRate];
    }

    /**
     * Land on /multi, select a rate so the calendar can paint availability (two rates
     * mean no auto-select), pick an available day, and wait for the rate steppers to
     * render. The dropdown rate only paints the calendar — the cart resolves all rates.
     */
    private function openCartWithDates(Browser $browser, int $rateToPaint): void
    {
        $browser->visit('/multi')->waitFor('#availability-search-rate');

        $browser->select('#availability-search-rate', (string) $rateToPaint)
            ->pause(500) // let the wire:model.live round-trip land data.rate before opening the calendar
            ->click('[name=datepicker]')
            ->waitFor('.rc-day__label')
            ->click('.rc-day--available:not(.rc-day--hidden)')
            ->waitFor('@multi-rate-increase-'.$rateToPaint);
    }

    /**
     * Click a rate's "+" stepper once and wait for its quantity to read 1. addSelections
     * resets the steppers to 0, so this is reused for each cart round.
     */
    private function stepRateToOne(Browser $browser, int $rateId): void
    {
        $browser->waitFor('@multi-rate-increase-'.$rateId)
            ->click('@multi-rate-increase-'.$rateId)
            ->waitForTextIn('@multi-rate-quantity-'.$rateId, '1');
    }
}

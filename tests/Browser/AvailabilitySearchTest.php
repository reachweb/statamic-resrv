<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;

/**
 * AvailabilitySearch — the richest Alpine surface in the addon. These drive the
 * reachweb/alpine-calendar, the entangled quantity stepper, the rates <select>,
 * the live auto-dispatch and #[Session] persistence in a REAL browser — exactly
 * the JS/DOM behaviours the 328 headless Livewire tests can only approximate.
 * Validation/pricing/state rules stay headless; here we confirm they RENDER.
 *
 * (This does not exercise AvailabilityControl — a separate, empty-<div> component
 * with no browser surface — see the board's "Intentionally NOT browser-tested".)
 *
 * Seed note: the bookable entry carries availability=1, so these keep quantity at
 * 1 wherever availability must resolve; the stepper test exercises >1 purely as
 * Alpine reactivity. Multi-rate selection / anyRate need ≥2 rates and are covered
 * by T15 (cart) and T20 (cross-collection reconciliation).
 */
class AvailabilitySearchTest extends BrowserTestCase
{
    public function test_single_pick_dispatches_live_results_and_clear_reverts_them(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('[name=datepicker]');

            // Open the calendar; show-availability-on-calendar paints a price label
            // on each seeded day through the $wire.availabilityCalendar() round-trip.
            $browser->click('[name=datepicker]')
                ->waitFor('.rc-popup-overlay')
                ->assertVisible('.rc-popup-overlay')
                ->waitFor('.rc-day__label');

            // live=true: a single-mode pick auto-runs search() with NO submit click,
            // so the results Book Now action (gated on resolved availability) appears.
            $browser->click('.rc-day--available:not(.rc-day--hidden)')
                ->waitFor('[wire\\:click="checkout()"]')
                ->assertPresent('[wire\\:click="checkout()"]');
            $this->assertNotEmpty($browser->value('[name=datepicker]'));

            // clearDates() resets the form and re-dispatches → results revert and the
            // calendar input empties (the clear button shows once a date is set).
            $browser->click('[aria-label="Clear selection"]')
                ->waitUntilMissing('[wire\\:click="checkout()"]');
            $this->assertEmpty($browser->value('[name=datepicker]'));
        });
    }

    public function test_range_mode_selects_a_two_date_span(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/__t/search-range')->waitFor('[name=datepicker]');

            $browser->click('[name=datepicker]')
                ->waitFor('.rc-popup-overlay')
                ->waitFor('.rc-day--available:not(.rc-day--hidden)');

            // Range mode needs two distinct day clicks; target two available days by
            // index in one script (real click events the calendar's delegated handler
            // processes) so both land before Alpine's reactive re-render detaches them.
            // :not(.rc-day--hidden) is load-bearing: the 2-month grid repaints each
            // in-window date a second time as a hidden other-month filler that still
            // carries rc-day--available, so the unfiltered NodeList interleaves hidden
            // duplicate dates and these indexes could land on an unclickable cell.
            $browser->script(<<<'JS'
                const days = [...document.querySelectorAll('.rc-day--available:not(.rc-day--hidden)')];
                days[1].click();
                days[4].click();
            JS);

            // The calendar writes "DD Mon YYYY – DD Mon YYYY" into the readonly input.
            $rangePattern = '/\d{1,2} \w{3} \d{4}.+\d{1,2} \w{3} \d{4}/u';
            $browser->waitUsing(5, 100, fn () => preg_match(
                $rangePattern,
                (string) $browser->value('[name=datepicker]')
            ) === 1);

            $this->assertMatchesRegularExpression(
                $rangePattern,
                (string) $browser->value('[name=datepicker]')
            );
        });
    }

    public function test_quantity_stepper_is_reactive_and_clamps_at_the_minimum(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('@quantity-input');

            // Starts at 1 with the decrement disabled (Alpine x-bind:disabled). The
            // input is disabled/value-bound, so read the .value/.disabled DOM props
            // rather than text content.
            $this->assertSame('1', $browser->value('@quantity-input'));
            $this->assertTrue($this->decrementDisabled($browser));

            // Increment is reactive and re-enables the decrement.
            $browser->click('@quantity-increase')
                ->waitUsing(5, 100, fn () => $browser->value('@quantity-input') === '2');
            $this->assertFalse($this->decrementDisabled($browser));

            // Decrement back to the clamp; the button disables again.
            $browser->click('@quantity-decrease')
                ->waitUsing(5, 100, fn () => $browser->value('@quantity-input') === '1');
            $this->assertTrue($this->decrementDisabled($browser));
        });
    }

    public function test_the_rates_dropdown_renders_with_the_single_rate_auto_selected(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('#availability-search-rate');

            // reconcileRate() auto-selects when exactly one rate is valid, so the
            // <select> shows that rate's numeric id, not the "any" sentinel.
            $value = $browser->value('#availability-search-rate');
            $this->assertNotSame('any', $value);
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $value);
        });
    }

    public function test_the_search_cart_survives_a_full_page_reload(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('[name=datepicker]');

            $browser->click('[name=datepicker]')
                ->waitFor('.rc-day__label')
                ->click('.rc-day--available:not(.rc-day--hidden)')
                ->waitFor('[wire\\:click="checkout()"]');

            $picked = $browser->value('[name=datepicker]');
            $this->assertNotEmpty($picked);

            // A real page reload: #[Session('resrv-search')] is FILE-backed, so the
            // dates and the resolved results must come back without re-picking
            // (Gotcha #4 — array session would reset the cart here and 404 checkout).
            $browser->refresh()
                ->waitFor('[name=datepicker]')
                ->waitFor('[wire\\:click="checkout()"]');

            $this->assertSame($picked, $browser->value('[name=datepicker]'));
        });
    }

    public function test_a_validation_error_renders_in_the_search_ui(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('[name=datepicker]');

            // Pick a valid date first so Alpine's local dates are non-empty (the error
            // block is x-show="!isDatesEmpty"), then push an invalid pair (start after
            // end) straight onto the Livewire component. The rule logic is headless-
            // tested; this only confirms the error UI renders in a real browser.
            $browser->click('[name=datepicker]')
                ->waitFor('.rc-day__label')
                ->click('.rc-day--available:not(.rc-day--hidden)')
                ->waitFor('[wire\\:click="checkout()"]');

            $browser->script(<<<'JS'
                const component = window.Livewire.all()
                    .find(c => c.el.querySelector('[name=datepicker]'));
                window.Livewire.find(component.id).set('data.dates', { date_start: '2099-12-20', date_end: '2099-12-10' });
            JS);

            $browser->waitFor('.text-red-600')->assertVisible('.text-red-600');
        });
    }

    /**
     * Read the decrement button's live disabled state via the DOM property — the
     * stepper toggles it through Alpine x-bind:disabled, which a Dusk attribute
     * assertion reads inconsistently for a boolean attribute.
     */
    private function decrementDisabled(Browser $browser): bool
    {
        return (bool) $browser->script(
            "return document.querySelector('[dusk=quantity-decrease]').disabled"
        )[0];
    }
}

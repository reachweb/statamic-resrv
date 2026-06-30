<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;

/**
 * First green feature browser test. Two payoffs:
 *
 *  1. Locks in the global-leak fix (commit 4c93a2f) as an automated regression.
 *     The frontend bundle is a classic <script>; before the IIFE wrap its 103
 *     top-level declarations all leaked onto window — a minified date-parser
 *     landed on window.L and collided with Leaflet ("L is not a function" after a
 *     wire:navigate). This test asserts in a real browser that only the deliberate
 *     window.dayjs global survives and window.L is gone — exactly the collision a
 *     headless Livewire test can never see (Gotcha #3).
 *  2. Proves the harness drives real Alpine + Livewire: the @reachweb/alpine-
 *     calendar opens, paints availability/price via a $wire round-trip, and a date
 *     pick round-trips again into rendered results.
 */
class SmokeTest extends BrowserTestCase
{
    public function test_only_dayjs_leaks_to_window_and_no_severe_console_errors(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('[name=datepicker]');

            // The IIFE leaves ONLY the intentional window.dayjs global; the leaked
            // date-parser that landed on window.L (and broke Leaflet) is gone.
            // Assert the known-bad global by name — never snapshot Object.keys(window)
            // (it varies across Chrome versions → flaky).
            $this->assertSame('function', $browser->script('return typeof window.dayjs')[0]);
            $this->assertSame('undefined', $browser->script('return typeof window.L')[0]);

            // No SEVERE JS console entries (the window.L collision surfaced as a
            // SEVERE TypeError). The served harness ships no favicon, so its 404 is
            // the one known-benign SEVERE entry and is filtered out by name — a real
            // JS error or a missing bundle (also SEVERE, but not favicon) still fails.
            $severe = array_values(array_filter(
                $browser->driver->manage()->getLog('browser'),
                fn (array $entry): bool => ($entry['level'] ?? '') === 'SEVERE'
                    && ! str_contains($entry['message'] ?? '', 'favicon.ico')
            ));

            $this->assertSame([], $severe, 'Unexpected SEVERE console entries: '.json_encode($severe));
        });
    }

    public function test_calendar_opens_paints_availability_and_a_date_pick_round_trips(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/bookable')->waitFor('[name=datepicker]');

            // Open the @reachweb/alpine-calendar popup.
            $browser->click('[name=datepicker]')
                ->waitFor('.rc-popup-overlay')
                ->assertVisible('.rc-popup-overlay');

            // showAvailabilityOnCalendar=true: opening the calendar fires the
            // $wire.availabilityCalendar() round-trip, which paints a price label
            // (.rc-day__label = currency symbol + price) on each seeded day — the
            // first availability/price text rendered through a real /livewire/update.
            $browser->waitFor('.rc-day__label');
            $this->assertGreaterThan(
                0,
                (int) $browser->script('return document.querySelectorAll(".rc-day__label").length')[0]
            );

            // Pick the first available day (single mode → one click selects it) and
            // let the data.dates round-trip resolve. The Book Now action is gated on
            // availability message.status === true, so its arrival proves the pick
            // round-tripped into rendered results.
            $browser->click('.rc-day--available')
                ->waitFor('[wire\\:click="checkout()"]')
                ->assertPresent('[wire\\:click="checkout()"]');

            // The selection was also written into the readonly date input.
            $this->assertNotEmpty($browser->value('[name=datepicker]'));
        });
    }
}

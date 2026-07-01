<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Depends;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Proves the testbench-dusk harness itself before any Phase-4 scenario is built:
 * the browser boots and renders, the served (browser) process and the test
 * process share one file SQLite across the HTTP boundary (Gotcha #5), and
 * DatabaseTruncation wipes that shared file between tests while setUp() re-seeds
 * the bookable fixtures. If any of these fail the lifecycle is wrong and every
 * downstream browser test would be built on sand — so this is the gate.
 */
class HarnessTest extends BrowserTestCase
{
    /**
     * The browser boots and the served Statamic + Livewire app renders. The plan's
     * literal visit('/')->assertPresent('body') doesn't translate: the harness
     * content set (T7) seeds no home entry, so '/' resolves to Statamic's bodyless
     * 404; and Dusk's resolver prefixes selectors with `body `, so a bare 'body'
     * selector becomes the impossible `body body`. The seeded /bookable page proves
     * the same intent against a URL that actually renders — asserting the Livewire
     * root (wire:id) and the calendar's date input makes this a real render proof,
     * not just "some HTML came back".
     */
    public function test_boots(): void
    {
        $this->browse(fn (Browser $browser) => $browser
            ->visit('/bookable')
            ->assertPresent('[wire\\:id]')
            ->assertPresent('[name=datepicker]'));
    }

    /**
     * (a) Cross-process visibility — a row the *browser* causes the served process
     * to write is readable by the *test* process querying the same file DB. The
     * served app inserts the reservation through the /__t/write-reservation
     * support route and echoes its reference; we read that straight off the page
     * and confirm the test process sees the identical row. Deliberately left
     * uncleaned so the next test can prove truncation removed it.
     */
    public function test_a_row_written_by_the_browser_is_visible_to_the_test_process(): string
    {
        $this->assertSame(0, Reservation::count(), 'The shared DB should start with no reservations.');

        $reference = '';

        $this->browse(function (Browser $browser) use (&$reference) {
            $browser->visit('/__t/write-reservation');
            $reference = trim($browser->text('@written-reservation'));
        });

        $this->assertNotEmpty($reference, 'The served process should have echoed the written reference.');
        $this->assertTrue(
            Reservation::where('reference', $reference)->exists(),
            'The reservation the served process wrote must be visible to the test process across the file DB.'
        );

        return $reference;
    }

    /**
     * (b) Clean-but-seeded isolation — this test runs after (a) yet starts with
     * zero reservations (DatabaseTruncation wiped (a)'s row across the test
     * boundary) while the seed fixtures are present again (setUp() re-seeded the
     * availability window and the rate). #[Depends] pins the order so (a)'s row
     * exists to be wiped before this runs.
     */
    #[Depends('test_a_row_written_by_the_browser_is_visible_to_the_test_process')]
    public function test_truncation_clears_prior_rows_but_re_seeds_the_fixtures(string $reference): void
    {
        $this->assertSame(0, Reservation::count(), "Truncation should have removed the previous test's reservation.");
        $this->assertFalse(
            Reservation::where('reference', $reference)->exists(),
            'The row written by the previous test must not survive into this one.'
        );

        $this->assertGreaterThan(0, Availability::count(), 'setUp() should have re-seeded the availability window.');
        $this->assertTrue(
            Rate::where('collection', 'pages')->where('slug', 'default')->exists(),
            'setUp() should have re-seeded the default rate.'
        );
    }
}

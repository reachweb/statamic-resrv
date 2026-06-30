<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Dusk\Options as DuskOptions;
use Orchestra\Testbench\Dusk\TestCase;
use Reach\StatamicResrv\Tests\Browser\Concerns\SeedsBookableContent;

/**
 * Base class for the testbench-dusk browser suite.
 *
 * WithWorkbench boots the same stack as a `testbench serve`: it loads
 * testbench.yaml (providers, env, the file SQLite path) and the workbench/ app,
 * so the WorkbenchServiceProvider already supplies the Statamic config, the
 * offline gateway, the manifest, the sites, the /livewire/update route and the
 * Outpost stub. That makes this base deliberately thin — there is no need to
 * re-port AddonTestCase's defineEnvironment() bits, they arrive through the
 * provider.
 *
 * Fixture lifecycle (Gotcha #5): the browser runs in a *separate* process and
 * can't see a :memory: DB, so testbench.yaml points both processes at the same
 * file SQLite. DatabaseTruncation — not DatabaseTransactions (a transaction
 * never crosses the HTTP request) and not DatabaseMigrations (testbench-dusk owns
 * its own rollback and the two fight) — wipes that shared file between tests, and
 * setUp() re-seeds it through SeedsBookableContent. Statamic entries live on disk
 * and survive truncation; the trait is find-or-create, so re-seeding repopulates
 * the DB-backed rows (incl. the resrv_entries the EntrySaved listener rebuilds)
 * without duplicating the on-disk content.
 *
 * $baseServePort stays at testbench-dusk's default 8001 to match
 * APP_URL=http://127.0.0.1:8001 from testbench.yaml (Gotcha #6).
 */
abstract class BrowserTestCase extends TestCase
{
    use DatabaseTruncation;
    use SeedsBookableContent;
    use WithWorkbench;

    public static function setUpBeforeClass(): void
    {
        // Headless is testbench-dusk's default; these make the headless run
        // deterministic and CI-safe (T21) and are harmless locally.
        DuskOptions::addArgument('--no-sandbox');
        DuskOptions::addArgument('--disable-gpu');
        DuskOptions::addArgument('--window-size=1400,1200');

        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableContent();
        $this->clearFrontendSessions();
    }

    /**
     * Give every browser test a clean cart. The frontend persists its
     * search/reservation cart in #[Session('resrv-search')] keys backed by the
     * FILE session driver (Gotcha #4) — and, unlike the truncated DB, those session
     * files survive across Dusk tests, so a date/rate left by one test would leak
     * into the next (e.g. a stale far-future date moves the calendar view off the
     * seeded window). config('session.files') resolves to the SAME directory the
     * served app writes (both run on the testbench-dusk skeleton), so wiping it here
     * makes the browser's carried session cookie resolve to a fresh empty session.
     */
    protected function clearFrontendSessions(): void
    {
        $directory = config('session.files');

        if (! is_string($directory) || ! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') as $file) {
            if (is_file($file) && basename($file) !== '.gitignore') {
                @unlink($file);
            }
        }
    }
}

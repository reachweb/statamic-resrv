<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Reach\StatamicResrv\Tests\Browser\Concerns\SeedsBookableContent;

/**
 * Browser-testing harness database seeder.
 *
 * Run by `workbench:build`'s migrate-fresh step so a `testbench serve` (and the
 * Dusk suite) boots with one bookable entry, a rate, a wide availability window,
 * an extra, an option, and the checkout entries. The seeding logic lives in the
 * shared SeedsBookableContent trait so the served app and the Dusk fixtures use
 * one implementation.
 */
class DatabaseSeeder extends Seeder
{
    use SeedsBookableContent;

    public function run(): void
    {
        $this->seedBookableContent();
    }
}

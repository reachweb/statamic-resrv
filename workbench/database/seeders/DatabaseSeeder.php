<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Browser-testing harness database seeder.
 *
 * Scaffolded as a stub in T4 so testbench.yaml's seeders list resolves. T7 ports
 * the bookable-content seeding here (a `pages` collection + resrv blueprint, a
 * Rate, a wide Availability window, an Extra, an Option, and the checkout
 * entries), reusing the logic from tests/CreatesEntries + tests/Livewire/CheckoutTest.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}

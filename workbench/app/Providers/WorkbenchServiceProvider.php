<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Browser-testing harness service provider.
 *
 * Scaffolded as a stub in T4 so testbench.yaml's providers list resolves and the
 * workbench app boots and builds. T5 fleshes out boot() with the Statamic config
 * (sites, stache stores, pro edition), the offline-only payment gateway, and the
 * /livewire/update route registered ahead of Statamic's catch-all.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}

<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Statamic\Facades\Site;
use Statamic\Licensing\Outpost;

use function Orchestra\Testbench\workbench_path;

/**
 * Browser-testing harness service provider.
 *
 * Boots the served Workbench app the way the headless suite boots Statamic, but
 * for a real HTTP process: it points the Stache at this package's workbench/
 * content, forces the Stripe-free offline gateway, registers the English site,
 * beats Statamic's catch-all to the /livewire/update endpoint, and stubs the
 * licensing Outpost so boot never hits the network.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->configureStatamic();
        $this->forceOfflineGateway();
    }

    public function boot(): void
    {
        $this->registerSites();
        $this->registerLivewireUpdateRoute();
        $this->preventOutpostRequests();
    }

    /**
     * Mirror the Statamic config the headless AddonTestCase applies, but resolve
     * the Stache directories against this package's workbench/ dir via
     * workbench_path() — base_path() would resolve to the testbench-dusk Laravel
     * skeleton, not here, leaving the served app with no content to read.
     */
    protected function configureStatamic(): void
    {
        config([
            'statamic.editions.pro' => true,
            'statamic.users.repository' => 'file',
            'statamic.stache.watcher' => false,
            'statamic.stache.stores.taxonomies.directory' => workbench_path('content/taxonomies'),
            'statamic.stache.stores.terms.directory' => workbench_path('content/taxonomies'),
            'statamic.stache.stores.collections.directory' => workbench_path('content/collections'),
            'statamic.stache.stores.entries.directory' => workbench_path('content/collections'),
            'statamic.stache.stores.navigation.directory' => workbench_path('content/navigation'),
            'statamic.stache.stores.globals.directory' => workbench_path('content/globals'),
            'statamic.stache.stores.global-variables.directory' => workbench_path('content/globals'),
            'statamic.stache.stores.asset-containers.directory' => workbench_path('content/assets'),
            'statamic.stache.stores.nav-trees.directory' => workbench_path('content/structures/navigation'),
            'statamic.stache.stores.collection-trees.directory' => workbench_path('content/structures/collections'),
            'statamic.stache.stores.form-submissions.directory' => workbench_path('content/submissions'),
            'statamic.stache.stores.users.directory' => workbench_path('users'),
        ]);
    }

    /**
     * Force the multi-gateway config down to the offline gateway only, so the
     * PaymentGatewayManager auto-selects it and the browser never meets a Stripe
     * card iframe. A real entry in payment_gateways (plural) takes precedence over
     * the single PaymentInterface binding, so the served app drives the offline
     * confirm path end-to-end.
     */
    protected function forceOfflineGateway(): void
    {
        config([
            'resrv-config.payment_gateways' => [
                'offline' => [
                    'class' => OfflinePaymentGateway::class,
                    'label' => 'Pay on arrival',
                ],
            ],
        ]);
    }

    protected function registerSites(): void
    {
        Site::setSites([
            'en' => [
                'name' => 'English',
                'url' => '/',
                'locale' => 'en_US',
                'lang' => 'en',
            ],
        ]);
    }

    /**
     * Replicate tests/TestCase::registerLivewireUpdateRoute() (Gotcha #8). Livewire
     * 4 mints a dynamic update endpoint; registering it inside booted() lands it
     * before Statamic's catch-all web route, so /livewire/update returns 200 rather
     * than being shadowed into a 404 that kills every interactive round-trip.
     */
    protected function registerLivewireUpdateRoute(): void
    {
        $this->app->booted(function () {
            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle)
                    ->middleware('web')
                    ->name('livewire.update');
            });
        });
    }

    /**
     * Stub the licensing Outpost (mirrors tests/TestCase::preventOutpostRequests())
     * so the served app never makes a real request to outpost.statamic.com during a
     * browser run.
     */
    protected function preventOutpostRequests(): void
    {
        $this->app->instance(Outpost::class, \Mockery::mock(Outpost::class)->shouldIgnoreMissing());
    }
}

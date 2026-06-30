<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\StatamicResrvServiceProvider;
use ReflectionClass;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Licensing\Outpost;
use Statamic\Statamic;

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
        $this->registerAddonManifest();
        $this->configureStatamic();
        $this->forceOfflineGateway();
    }

    public function boot(): void
    {
        $this->registerSites();
        $this->registerLivewireUpdateRoute();
        $this->resolveCheckoutEntries();
        $this->preventOutpostRequests();
    }

    /**
     * Register the addon in Statamic's manifest the way AddonTestCase does for the
     * headless suite. The addon is the repo's *root* composer package, so it never
     * lands in vendor/composer/installed.json and Statamic never discovers it —
     * leaving AddonServiceProvider::boot() to bail at its `if (! $this->getAddon())`
     * guard, which silently skips the entire boot chain (events, tags, scopes,
     * fieldtypes, publishables, bootAddon). Injecting the manifest entry makes
     * getAddon() resolve by namespace, so the served app boots the addon exactly
     * like a real install: the resrv_availability fieldtype registers, the tags
     * render, and the frontend assets publish under the `statamic-resrv` tag.
     *
     * Unlike AddonTestCase we must *append* to — not replace — the discovered
     * manifest: marcorieser/statamic-livewire is itself an AddonServiceProvider and
     * registers the `livewire` Antlers tag (the <livewire:…> bridge) through its
     * own gated bootTags(). Wiping it from the manifest leaves that tag
     * unregistered, so <livewire:…> renders as literal text. build() repopulates
     * the in-memory manifest from installed.json (restoring the bridge); we then
     * add the root package on top.
     */
    protected function registerAddonManifest(): void
    {
        $provider = StatamicResrvServiceProvider::class;
        $directory = dirname((new ReflectionClass($provider))->getFileName());
        $namespace = implode('\\', explode('\\', $provider, -1));

        $json = json_decode($this->app['files']->get($directory.'/../composer.json'), true);
        $statamic = $json['extra']['statamic'] ?? [];

        $manifest = $this->app->make(Manifest::class);
        $manifest->build();

        $manifest->manifest = array_merge($manifest->manifest, [
            $json['name'] => [
                'id' => $json['name'],
                'slug' => $statamic['slug'] ?? null,
                'version' => 'dev-main',
                'namespace' => $namespace,
                'autoload' => $json['autoload']['psr-4'][$namespace.'\\'] ?? 'src',
                'provider' => $provider,
            ],
        ]);
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
     * Register Livewire's /livewire/update endpoint ahead of Statamic's front-end
     * catch-all (Gotcha #8). Statamic's routes/web.php registers an `ANY {segments?}`
     * site route (name `statamic.site`) that matches every method and path — POST
     * /livewire/update included — and returns its own 404 when no entry resolves at
     * that URI. Same-method routes match in registration order, so whatever lands
     * before that catch-all wins.
     *
     * Routes pushed through Statamic::pushWebRoutes() flush at
     * `Statamic::additionalWebRoutes()`, which Statamic calls immediately *before*
     * registering the catch-all — so they take precedence (the addon's own
     * resrv/api/webhook routes ride in exactly this way). setUpdateRoute() registers
     * the route synchronously, so calling it inside the pushed closure places
     * /livewire/update before the catch-all and the interactive round-trip 200s.
     *
     * The headless tests/TestCase registers this inside $app->booted() instead, which
     * fires *after* the catch-all is already in the table — that never wins the match,
     * but the headless suite never noticed because Livewire::test() bypasses HTTP
     * routing. The served browser app is the first place a real POST is issued, so it
     * must use the pushWebRoutes path.
     */
    protected function registerLivewireUpdateRoute(): void
    {
        Statamic::pushWebRoutes(function () {
            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle)
                    ->middleware('web')
                    ->name('livewire.update');
            });
        });
    }

    /**
     * Point resrv-config.checkout_entry / checkout_completed_entry at the seeded
     * entries (Gotcha #9). The served app is a separate process that never runs
     * the SeedsBookableContent trait, so it re-resolves the same entries by slug
     * here — the SeedsBookableContent::wireCheckoutEntries() Config::set covers
     * the seeding/test process the same way. Resolved by slug (not a baked ID) so
     * every process agrees on the disk-persisted Statamic IDs. Deferred to booted()
     * and guarded on the collection so the early build phases (before the seeder
     * has run) are a no-op rather than an error.
     */
    protected function resolveCheckoutEntries(): void
    {
        $this->app->booted(function () {
            if (! Collection::findByHandle('pages')) {
                return;
            }

            $resolve = fn (string $slug) => Entry::query()
                ->where('collection', 'pages')
                ->where('slug', $slug)
                ->first();

            if ($checkout = $resolve('checkout')) {
                config(['resrv-config.checkout_entry' => $checkout->id()]);
            }

            if ($completed = $resolve('checkout-completed')) {
                config(['resrv-config.checkout_completed_entry' => $completed->id()]);
            }
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

<?php

namespace Reach\StatamicResrv\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use MarcoRieser\Livewire\ServiceProvider;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\StatamicResrvServiceProvider;
use Spatie\LaravelRay\RayServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Licensing\Outpost;
use Statamic\Stache\Stores\UsersStore;
use Statamic\Statamic;
use Statamic\Support\Str;
use Statamic\Testing\AddonTestCase;

class TestCase extends AddonTestCase
{
    use FakesViews;
    use PreventSavingStacheItemsToDisk;
    use RefreshDatabase;
    use WithFaker;

    protected string $addonServiceProvider = StatamicResrvServiceProvider::class;

    /** @var callable|null */
    private $baselineErrorHandler;

    /** @var callable|null */
    private $baselineExceptionHandler;

    protected function setUp(): void
    {
        $this->captureHandlerBaseline();

        parent::setUp();

        // Clear any Stache items left in the fake directory by a previous test
        // process that was interrupted before its tearDown ran (a killed run, a
        // crash, a Ctrl-C). Statamic only wipes this directory in tearDown, so a
        // stray entry file otherwise survives on disk and the first test to query
        // that collection loads it as a phantom extra entry — a flaky, run-to-run
        // contamination that never reproduces in a clean checkout.
        $this->deleteFakeStacheDirectory();

        $this->resetPostgresSequences();

        $this->preventOutpostRequests();

        $this->withoutExceptionHandling();

        Rate::resetEntryCollectionCache();

        Site::setSites([
            'en' => [
                'name' => 'English',
                'url' => 'http://localhost/',
                'locale' => 'en_US',
                'lang' => 'en',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreHandlerBaseline();
    }

    protected function setUpFaker()
    {
        $this->faker = $this->makeFaker();
    }

    /**
     * Record the error/exception handlers that are active before the application
     * boots, so any handlers leaked during the test can be drained back to this
     * baseline in tearDown.
     *
     * Testbench rebuilds the whole application for every test, and each boot of
     * Laravel's HandleExceptions bootstrapper pushes PHP error/exception handlers
     * that the framework never pops. Once the stack is left unbalanced, PHPUnit
     * reports the *next* test as an error ("did not remove its own exception
     * handlers") — a cross-test artifact that surfaces intermittently in the full
     * suite while every test still passes in isolation.
     */
    private function captureHandlerBaseline(): void
    {
        $this->baselineErrorHandler = set_error_handler(null);
        restore_error_handler();

        $this->baselineExceptionHandler = set_exception_handler(null);
        restore_exception_handler();
    }

    /**
     * Pop any error/exception handlers pushed during the test until the stack is
     * back at the baseline captured in setUp. Only handlers above the baseline are
     * removed, so PHPUnit's own handler is left untouched.
     */
    private function restoreHandlerBaseline(): void
    {
        while (true) {
            $handler = set_error_handler(null);
            restore_error_handler();

            if ($handler === null || $handler === $this->baselineErrorHandler) {
                break;
            }

            restore_error_handler();
        }

        while (true) {
            $handler = set_exception_handler(null);
            restore_exception_handler();

            if ($handler === null || $handler === $this->baselineExceptionHandler) {
                break;
            }

            restore_exception_handler();
        }
    }

    // Stop the licensing Outpost (hit by the ContactOutpost CP middleware on
    // every CP route) from making real requests to outpost.statamic.com.
    protected function preventOutpostRequests(): void
    {
        $this->instance(Outpost::class, \Mockery::mock(Outpost::class)->shouldIgnoreMissing());
    }

    public function multisite($site = 'en'): void
    {
        Site::setCurrent($site);
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            LivewireServiceProvider::class,
            ServiceProvider::class,
            RayServiceProvider::class,
        ]);
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        // Disable Statamic's search indexing during tests. Every Entry::save()
        // fires EntrySaved, whose Statamic\Search\UpdateItemIndexes listener
        // re-indexes the configured local indexes — ~0.7s per save and the
        // single largest cost in the suite. The addon uses its own resrv_search
        // scope, never Statamic's search index, so this is safe.
        //
        // Note: Statamic's IndexManager always injects a hardcoded "cp" index on
        // the local driver, so emptying the array isn't enough — the cp index
        // must be explicitly redefined onto the no-op "null" driver.
        $app['config']->set('statamic.search.indexes', [
            'cp' => ['driver' => 'null'],
        ]);

        // Force the cache driver to be array for testing
        $app['config']->set('cache.default', 'array');

        // Force the session driver to be array for testing
        $app['config']->set('session.driver', 'array');

        // Force the queue driver to be sync for testing
        $app['config']->set('queue.default', 'sync');

        // Setting the user repository to the default flat file system
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.stache.watcher', false);
        $app['config']->set('statamic.stache.stores.users', [
            'class' => UsersStore::class,
            'directory' => __DIR__.'/__fixtures__/users',
        ]);

        // Set the path for our forms
        $app['config']->set('statamic.forms.forms', __DIR__.'/../resources/forms/');

        // Assume the pro edition within tests
        $app['config']->set('statamic.editions.pro', true);

        // Register Livewire update route before Statamic's catch-all route
        // This is needed for Livewire 4 which uses dynamic endpoint paths
        $this->registerLivewireUpdateRoute($app);

        Statamic::pushCpRoutes(function () {
            return require_once realpath(__DIR__.'/../routes/cp.php');
        });

        Statamic::pushWebRoutes(function () {
            return require_once realpath(__DIR__.'/../routes/web.php');
        });
    }

    protected function registerLivewireUpdateRoute($app): void
    {
        $app->booted(function () {
            // Livewire 4 uses dynamic endpoints based on APP_KEY hash
            // We need to set the update route before Statamic's catch-all route is matched
            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle)
                    ->middleware('web')
                    ->name('livewire.update');
            });
        });
    }

    protected function signInAdmin()
    {
        $user = User::make();
        $user->id(1)->email('test@test.com')->makeSuper();
        $this->be($user);

        return $user;
    }

    public function makeStatamicItem(?array $data = null)
    {
        $entryData = [
            'title' => $data['title'] ?? 'Test Statamic Item',
            'resrv_availability' => $data['resrv_availability'] ?? Str::random(6),
        ];

        $this->ensureCollectionExists('pages');

        Entry::make()
            ->collection('pages')
            ->slug($slug = Str::random(6))
            ->data($data ?? $entryData)
            ->save();

        return Entry::query()->where('slug', $slug)->first();
    }

    public function makeStatamicItemWithResrvAvailabilityField(?array $data = null, string $collectionHandle = 'pages')
    {
        $entryData = [
            'title' => $data['title'] ?? 'Test Statamic Item',
            'resrv_availability' => $data['resrv_availability'] ?? Str::random(6),
        ];

        $this->ensureCollectionWithResrvField($collectionHandle);

        Entry::make()
            ->collection($collectionHandle)
            ->slug($slug = Str::random(6))
            ->data($data ?? $entryData)
            ->save();

        return Entry::query()->where('slug', $slug)->first();
    }

    public function makeStatamicWithoutResrvAvailabilityField($data = null)
    {
        $entryData = [
            'title' => $data['title'] ?? 'Test Statamic Item',
        ];

        $this->ensureCollectionExists('something', '/something/{slug}');

        Entry::make()
            ->collection('something')
            ->slug($slug = Str::random(6))
            ->data($data ?? $entryData)
            ->save();

        return Entry::query()->where('slug', $slug)->first();
    }

    protected function ensureCollectionExists(string $handle, string $route = '/{slug}'): \Statamic\Contracts\Entries\Collection
    {
        if ($existing = Collection::findByHandle($handle)) {
            return $existing;
        }

        $collection = Collection::make($handle)->routes($route);
        $collection->save();

        return $collection;
    }

    protected function ensureCollectionWithResrvField(string $handle): \Statamic\Contracts\Entries\Collection
    {
        if ($existing = Collection::findByHandle($handle)) {
            return $existing;
        }

        $collection = Collection::make($handle)->routes('/{slug}');
        $collection->save();

        Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                        ['handle' => 'slug', 'field' => ['type' => 'text', 'display' => 'Slug']],
                        ['handle' => 'resrv_availability', 'field' => ['type' => 'resrv_availability', 'display' => 'Resrv Availability']],
                    ],
                ],
            ],
        ])->setHandle($handle)->setNamespace('collections.'.$handle)->save();

        return $collection;
    }

    /**
     * Assert that the database has a record with the given JSON column value.
     * Works across SQLite, MySQL, and PostgreSQL.
     */
    protected function assertDatabaseHasJsonColumn(string $table, array $data, string $jsonColumn, mixed $jsonValue): void
    {
        $query = DB::table($table);

        foreach ($data as $column => $value) {
            $query->where($column, $value);
        }

        $jsonString = is_string($jsonValue) ? $jsonValue : json_encode($jsonValue);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw("{$jsonColumn}::text = ?", [$jsonString]);
        } else {
            $query->where($jsonColumn, $jsonString);
        }

        $this->assertTrue($query->exists(), "Failed asserting that table [{$table}] has matching record with {$jsonColumn} = {$jsonString}");
    }

    /**
     * Bind a payment gateway mock whose refund() runs exactly once, returning $outcome or
     * throwing it when it is an exception.
     */
    protected function mockRefundGateway(bool|\Throwable $outcome = true): void
    {
        $gateway = \Mockery::mock(FakePaymentGateway::class)->makePartial();
        $expectation = $gateway->shouldReceive('refund')->once();
        $outcome instanceof \Throwable ? $expectation->andThrow($outcome) : $expectation->andReturn($outcome);

        // No call-count constraint: Reservation::canBeCancelledByCustomer() also resolves
        // the gateway (capability check) on every render, not just during the refund.
        $manager = \Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')->andReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    /**
     * Bind a payment gateway manager whose refund() must never run — for flows that must
     * reject before any money moves. Read-only resolution is allowed because renders resolve
     * the gateway for capability/display checks (refundIsAutomatic, amountPaidOnline); only an
     * actual refund call fails the test.
     */
    protected function forbidGatewayRefunds(): void
    {
        $gateway = \Mockery::mock(FakePaymentGateway::class)->makePartial();
        $gateway->shouldReceive('refund')->never();

        $manager = \Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')->andReturn($gateway);
        $manager->shouldReceive('gateway')->andReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    /**
     * Reset PostgreSQL sequences to 1 at the start of each test.
     *
     * RefreshDatabase rolls back data via transactions, but PG sequences
     * are non-transactional and keep advancing across tests. Resetting them
     * makes ID assignment deterministic and matches SQLite/MySQL behavior.
     */
    protected function resetPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $sequences = DB::select("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'");

        foreach ($sequences as $sequence) {
            DB::statement("ALTER SEQUENCE \"{$sequence->sequence_name}\" RESTART WITH 1");
        }
    }
}

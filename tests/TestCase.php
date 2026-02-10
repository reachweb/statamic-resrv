<?php

namespace Reach\StatamicResrv\Tests;

use Facades\Statamic\Version;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Extend\Manifest;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Stache\Stores\UsersStore;
use Statamic\Statamic;
use Statamic\Support\Str;

class TestCase extends OrchestraTestCase
{
    use DatabaseMigrations;
    use FakesViews;
    use PreventSavingStacheItemsToDisk;
    use WithFaker;

    protected $fakeStacheDirectory = __DIR__.'/__fixtures__/dev-null';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->preventSavingStacheItemsToDisk();
        $this->withoutExceptionHandling();

        Version::shouldReceive('get')->andReturn('5.5.0');

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
        $this->deleteFakeStacheDirectory();

        parent::tearDown();
    }

    protected function setUpFaker()
    {
        $this->faker = $this->makeFaker();
    }

    public function multisite($site = 'en'): void
    {
        Site::setCurrent($site);
    }

    protected function getPackageProviders($app)
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \MarcoRieser\Livewire\ServiceProvider::class,
            \Reach\StatamicResrv\StatamicResrvServiceProvider::class,
            \Spatie\LaravelRay\RayServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return ['Statamic' => Statamic::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Manifest::class)->manifest = [
            'reach/resrv' => [
                'id' => 'reach/resrv',
                'namespace' => 'Reach\\StatamicResrv',
            ],
        ];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $configs = [
            'assets', 'cp', 'forms', 'routes', 'static_caching',
            'stache', 'system', 'users',
        ];

        foreach ($configs as $config) {
            $app['config']->set("statamic.$config", require (__DIR__."/../vendor/statamic/cms/config/{$config}.php"));
        }

        // $app['config']->set("resrv-config", require(__DIR__."/../config/config.php"));

        // Force the cache driver to be array for testing
        $app['config']->set('cache.default', 'array');

        // Force the queue driver to be sync for testing
        $app['config']->set('queue.default', 'sync');

        // Setting the user repository to the default flat file system
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.stache.watcher', false);
        $app['config']->set('statamic.stache.stores.users', [
            'class' => UsersStore::class,
            'directory' => __DIR__.'/__fixtures/users',
        ]);
        // Set the path for our forms
        $app['config']->set('statamic.forms.forms', __DIR__.'/../resources/forms/');

        // Set the path for our entries
        $app['config']->set('statamic.stache.stores.taxonomies.directory', __DIR__.'/__fixtures__/content/taxonomies');
        $app['config']->set('statamic.stache.stores.terms.directory', __DIR__.'/__fixtures__/content/taxonomies');
        $app['config']->set('statamic.stache.stores.collections.directory', __DIR__.'/__fixtures__/content/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', __DIR__.'/__fixtures__/content/collections');
        $app['config']->set('statamic.stache.stores.navigation.directory', __DIR__.'/__fixtures__/content/navigation');
        $app['config']->set('statamic.stache.stores.globals.directory', __DIR__.'/__fixtures__/content/globals');
        $app['config']->set('statamic.stache.stores.global-variables.directory', __DIR__.'/__fixtures__/content/globals');
        $app['config']->set('statamic.stache.stores.asset-containers.directory', __DIR__.'/__fixtures__/content/assets');

        // Assume the pro edition within tests
        $app['config']->set('statamic.editions.pro', true);

        // Enable legacy endpoints for testing
        $app['config']->set('resrv-config.enable_legacy_endpoints', true);

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

        Collection::make('pages')->routes('/{slug}')->save();

        Entry::make()
            ->collection('pages')
            ->slug($slug = Str::random(6))
            ->data($data ?? $entryData)
            ->save();

        return Entry::query()->where('slug', $slug)->first();
    }

    public function makeStatamicItemWithResrvAvailabilityField(?array $data = null)
    {
        $entryData = [
            'title' => $data['title'] ?? 'Test Statamic Item',
            'resrv_availability' => $data['resrv_availability'] ?? Str::random(6),
        ];

        $collection = Collection::make('pages')->routes('/{slug}')->save();

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Title',
                            ],
                        ],
                        [
                            'handle' => 'slug',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Slug',
                            ],
                        ],
                        [

                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->setHandle('pages')->setNamespace('collections.'.$collection->handle())->save();

        Entry::make()
            ->collection('pages')
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

        Collection::make('something')->routes('/something/{slug}')->save();

        Entry::make()
            ->collection('something')
            ->slug($slug = Str::random(6))
            ->data($data ?? $entryData)
            ->save();

        return Entry::query()->where('slug', $slug)->first();
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
}

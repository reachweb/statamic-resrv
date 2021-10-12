<?php

namespace Reach\StatamicResrv\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\WithFaker;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Statamic;
use Statamic\Extend\Manifest;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Support\Str;
use Statamic\Facades\User;
use Facades\Statamic\Version;
use Statamic\Stache\Stores\UsersStore;

class TestCase extends OrchestraTestCase
{
    use DatabaseMigrations;
    use WithFaker;
    use PreventSavingStacheItemsToDisk;

    protected function setUp(): void
    {       
        parent::setUp();

        $this->preventSavingStacheItemsToDisk();
        
        Blueprint::setDirectory(__DIR__.'/../resources/blueprints');

        Version::shouldReceive('get')->andReturn('3.1.5');
        $this->addToAssertionCount(-1); // Dont want to assert this

        $this->withoutExceptionHandling();
    }

    public function tearDown(): void
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
            \Reach\StatamicResrv\StatamicResrvServiceProvider::class,
            \Spatie\LaravelRay\RayServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return ['Statamic' => Statamic::class,];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Manifest::class)->manifest = [
            'reach/resrv' => [
                'id' => 'reach/resrv',
                'namespace' => 'Reach\\StatamicResrv\\',
            ],
        ];

    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $configs = [
            'assets', 'cp', 'forms', 'routes', 'static_caching',
            'sites', 'stache', 'system', 'users',
        ];

        foreach ($configs as $config) {
            $app['config']->set("statamic.$config", require(__DIR__."/../vendor/statamic/cms/config/{$config}.php"));
        }

        //$app['config']->set("resrv-config", require(__DIR__."/../config/config.php"));

        // Setting the user repository to the default flat file system
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.stache.watcher', false);
        $app['config']->set('statamic.stache.stores.users', [
            'class'     => UsersStore::class,
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
        $app['config']->set('statamic.stache.stores.asset-containers.directory', __DIR__.'/__fixtures__/content/assets');

        // Assume the pro edition within tests
        $app['config']->set('statamic.editions.pro', true);

        Statamic::pushCpRoutes(function () {
            return require_once realpath(__DIR__.'/../routes/cp.php');
        });

        Statamic::pushWebRoutes(function () {
            return require_once realpath(__DIR__.'/../routes/web.php');
        });
      
    }

    protected function signInAdmin()
    {
        $user = User::make();
        $user->id(1)->email('test@test.com')->makeSuper();
        $this->be($user);

        return $user;
    }

    public function makeStatamicItem(array $data = null)
    {

        $entryData = [
            'title' => $data['title'] ?? 'Test Statamic Item',
            'resrv_availability' => $data['resrv_availability'] ?? Str::random('6')
        ];

        Collection::make('pages')->save();

        Entry::make()
            ->collection('pages')
            ->slug($slug = Str::random('6'))
            ->data($entryData)
            ->save();

        return Entry::findBySlug($slug, 'pages');

    }

}
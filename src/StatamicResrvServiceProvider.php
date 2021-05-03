<?php

namespace Reach\StatamicResrv;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Permission;
use Statamic\Facades\CP\Nav;

class StatamicResrvServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp'  => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $fieldtypes = [
        \Reach\StatamicResrv\Fieldtypes\Availability::class,
        \Reach\StatamicResrv\Fieldtypes\Extras::class,
    ];
    
    protected $tags = [
        \Reach\StatamicResrv\Tags\Resrv::class,
    ];

    protected $scripts = [
        __DIR__.'/../public/js/resrv.js',
    ];
    
    protected $stylesheets = [
        __DIR__.'/../public/css/resrv.css',
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'statamic-resrv');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/config.php' => config_path('resrv-config.php'),
        ], 'resrv-config');
        
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'resrv-config');

        $this->createNavigation();

        $this->bootPermissions();

    }

    private function createNavigation(): void
    {
        Nav::extend(function ($nav) {
            // Orders
            // $nav->create(ucfirst(__('butik::cp.order_plural')))
            //     ->section('Butik')
            //     ->can(auth()->user()->can('view orders'))
            //     ->route('butik.orders.index')
            //     ->icon('drawer-file');

            // Settings
            $nav->create(ucfirst(__('Extras')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.extras.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M5.5,7h13a5,5,0,0,1,5,5h0a5,5,0,0,1-5,5H5.5a5,5,0,0,1-5-5h0A5,5,0,0,1,5.5,7Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18 9.501L18 14.501" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 9.501L16 14.501" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');
        });
    }

    protected function bootPermissions(): void
    {
        $this->app->booted(function () {
            Permission::group('statamic-resrv', 'Reserv Permissions', function () {
                Permission::register('use resrv', function ($permission) {
                    $permission
                        ->label(__('Use Statamic Resrv'))
                        ->description(__('Allow usage of Resrv'));
                });                
            });
        });
    }

}

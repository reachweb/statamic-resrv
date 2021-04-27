<?php

namespace Reach\StatamicResrv;

use Statamic\Providers\AddonServiceProvider;

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
        
        if (app()->environment() == 'testing') {
            $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'resrv-config');
        }

    }

}

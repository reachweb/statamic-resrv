<?php

namespace Reach\StatamicResrv;

use Statamic\Providers\AddonServiceProvider;

class StatamicResrvServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        //'cp'  => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

    }
}

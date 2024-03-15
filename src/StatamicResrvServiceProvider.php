<?php

namespace Reach\StatamicResrv;

use Illuminate\Support\AggregateServiceProvider;

class StatamicResrvServiceProvider extends AggregateServiceProvider
{
    protected $providers = [
        \Reach\StatamicResrv\Providers\ResrvProvider::class,
        \Reach\StatamicResrv\Providers\ResrvLivewireProvider::class,
    ];
}
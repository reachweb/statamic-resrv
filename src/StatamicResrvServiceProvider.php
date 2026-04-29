<?php

namespace Reach\StatamicResrv;

use Illuminate\Support\AggregateServiceProvider;
use Reach\StatamicResrv\Providers\ResrvLivewireProvider;
use Reach\StatamicResrv\Providers\ResrvProvider;

class StatamicResrvServiceProvider extends AggregateServiceProvider
{
    protected $providers = [
        ResrvProvider::class,
        ResrvLivewireProvider::class,
    ];
}

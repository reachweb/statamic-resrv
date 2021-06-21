<?php

namespace Reach\StatamicResrv;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Permission;
use Statamic\Facades\CP\Nav;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
use Reach\StatamicResrv\Listeners\IncreaseAvailability;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\SendNewReservationEmails;
use Reach\StatamicResrv\Listeners\SendRefundReservationEmails;
use Reach\StatamicResrv\Filters\ReservationStatus;

class StatamicResrvServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp'  => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $fieldtypes = [
        \Reach\StatamicResrv\Fieldtypes\Availability::class,
        \Reach\StatamicResrv\Fieldtypes\Extras::class,
        \Reach\StatamicResrv\Fieldtypes\FixedPricing::class,
    ];
    
    protected $tags = [
        \Reach\StatamicResrv\Tags\Resrv::class,
    ];

    protected $scopes = [
        ReservationStatus::class,
    ];

    protected $listen = [
        ReservationCreated::class  => [
            AddReservationIdToSession::class,
            DecreaseAvailability::class,
        ],
        ReservationExpired::class  => [
            IncreaseAvailability::class,
        ],
        ReservationConfirmed::class  => [
            SendNewReservationEmails::class,
        ],
        ReservationRefunded::class  => [
            SendRefundReservationEmails::class,
        ],
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

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-resrv');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/config.php' => config_path('resrv-config.php'),
        ], 'resrv-config');

        $this->publishes([
            __DIR__.'/../resources/blueprints' => resource_path('blueprints'),
        ], 'resrv-blueprints');
        
        $this->publishes([
            __DIR__.'/../resources/forms' => resource_path('forms'),
        ], 'resrv-forms');

        $this->publishes([
            __DIR__.'/../resources/views/email' => resource_path('views/vendor/statamic-resrv/email'),
        ], 'resrv-views');
        
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'resrv-config');

        $this->app->bind(PaymentInterface::class, config('resrv-config.payment_gateway'));

        if (app()->environment() == 'testing') {
            $this->app->bind(PaymentInterface::class, \Reach\StatamicResrv\Http\Payment\FakePaymentGateway::class);
        }

        $this->createNavigation();

        $this->bootPermissions();        

    }

    private function createNavigation(): void
    {
        Nav::extend(function ($nav) {

            $nav->create(ucfirst(__('Reservations')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reservations.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,,,<g transform="matrix(1,0,0,1,0,0)"><path d="M18.018,17.562a2,2,0,0,0,.482-1.3V5.5H5.5v15h9.08a2,2,0,0,0,1.519-.7Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M20.5,2.5H15.052a3.329,3.329,0,0,0-6.1,0H3.5a1,1,0,0,0-1,1v19a1,1,0,0,0,1,1h17a1,1,0,0,0,1-1V3.5A1,1,0,0,0,20.5,2.5Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.5 8.5L8.5 8.5" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.5 12.5L8.5 12.5" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13 16.5L8.5 16.5" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');
                
            $nav->create(ucfirst(__('Locations')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.locations.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M8.500 7.000 A3.500 3.500 0 1 0 15.500 7.000 A3.500 3.500 0 1 0 8.500 7.000 Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M12,.5a6.856,6.856,0,0,1,6.855,6.856c0,3.215-4.942,11.185-6.434,13.517a.5.5,0,0,1-.842,0c-1.492-2.332-6.434-10.3-6.434-13.517A6.855,6.855,0,0,1,12,.5Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M17,19.7c3.848.324,6.5,1.009,6.5,1.8,0,1.105-5.148,2-11.5,2S.5,22.605.5,21.5c0-.79,2.635-1.473,6.458-1.8" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');
                
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

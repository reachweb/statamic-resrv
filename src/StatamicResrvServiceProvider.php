<?php

namespace Reach\StatamicResrv;

use Illuminate\Support\Facades\Route;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Events\AvailabilitySearch;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Filters\ReservationMadeDate;
use Reach\StatamicResrv\Filters\ReservationStartingDate;
use Reach\StatamicResrv\Filters\ReservationStartingDateYear;
use Reach\StatamicResrv\Filters\ReservationStatus;
use Reach\StatamicResrv\Http\Controllers\AdvancedAvailabilityController;
use Reach\StatamicResrv\Http\Controllers\AvailabilityController;
use Reach\StatamicResrv\Http\Controllers\ConfigController;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
use Reach\StatamicResrv\Listeners\EntryDeleted;
use Reach\StatamicResrv\Listeners\IncreaseAvailability;
use Reach\StatamicResrv\Listeners\SaveSearchToSession;
use Reach\StatamicResrv\Listeners\SendNewReservationEmails;
use Reach\StatamicResrv\Listeners\SendRefundReservationEmails;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class StatamicResrvServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $commands = [
        Console\Commands\InstallResrv::class,
    ];

    protected $fieldtypes = [
        \Reach\StatamicResrv\Fieldtypes\ResrvAvailability::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvOptions::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvExtras::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvFixedPricing::class,
    ];

    protected $tags = [
        \Reach\StatamicResrv\Tags\Resrv::class,
    ];

    protected $scopes = [
        ReservationMadeDate::class,
        ReservationStartingDate::class,
        ReservationStartingDateYear::class,
        ReservationStatus::class,
    ];

    protected $listen = [
        ReservationCreated::class => [
            AddReservationIdToSession::class,
            DecreaseAvailability::class,
        ],
        ReservationExpired::class => [
            IncreaseAvailability::class,
        ],
        ReservationConfirmed::class => [
            SendNewReservationEmails::class,
        ],
        ReservationRefunded::class => [
            SendRefundReservationEmails::class,
            IncreaseAvailability::class,
        ],
        AvailabilitySearch::class => [
            SaveSearchToSession::class,
        ],
        \Statamic\Events\EntryDeleted::class => [
            EntryDeleted::class,
        ],
    ];

    protected $scripts = [
        __DIR__.'/../public/js/resrv.js',
    ];

    protected $stylesheets = [
        __DIR__.'/../public/css/resrv.css',
    ];

    public function register()
    {
        $this->app->when(AvailabilityController::class)
          ->needs(AvailabilityContract::class)
          ->give(Availability::class);

        $this->app->when(AdvancedAvailabilityController::class)
          ->needs(AvailabilityContract::class)
          ->give(AdvancedAvailability::class);
    }

    public function boot(): void
    {
        parent::boot();

        Route::group([
            'middleware' => [
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ],
        ], function () {
            require __DIR__.'/../routes/payments.php';
        });

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'statamic-resrv');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-resrv');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/config.php' => config_path('resrv-config.php'),
        ], 'resrv-config');

        $this->publishes([
            __DIR__.'/../resources/views/checkout' => resource_path('views/vendor/statamic-resrv/checkout'),
        ], 'resrv-checkout-views');

        $this->publishes([
            __DIR__.'/../resources/blueprints' => resource_path('blueprints'),
        ], 'resrv-blueprints');

        $this->publishes([
            __DIR__.'/../resources/forms' => resource_path('forms'),
        ], 'resrv-forms');

        $this->publishes([
            __DIR__.'/../resources/views/email' => resource_path('views/vendor/statamic-resrv/email'),
        ], 'resrv-emails');

        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'resrv-config');

        $this->app->bind(PaymentInterface::class, config('resrv-config.payment_gateway'));

        if (app()->environment() == 'testing') {
            $this->app->bind(PaymentInterface::class, \Reach\StatamicResrv\Http\Payment\FakePaymentGateway::class);
        }

        $this->createNavigation();

        \Edalzell\Forma\Forma::add('reachweb/statamic-resrv', ConfigController::class);

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

            $nav->create(ucfirst(__('Reports')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reports.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g transform="matrix(1,0,0,1,0,0)"><defs></defs><circle fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" cx="7" cy="8.5" r="3.5"></circle><polyline fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" points="7 5 7 8.5 10.5 8.5"></polyline><path fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" d="M9,22.5a6.979,6.979,0,0,0,1.5-4"></path><path fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" d="M15,22.5a6.979,6.979,0,0,1-1.5-4"></path><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="7.499" y1="22.5" x2="16.499" y2="22.5"></line><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="0.5" y1="15.5" x2="23.5" y2="15.5"></line><rect fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x="0.5" y="1.5" width="23" height="17" rx="1" ry="1"></rect><polyline fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" points="13.5 7 15 5 18 7.5 20.5 4.5"></polyline><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="19.5" y1="12.5" x2="19.5" y2="11"></line><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="17.5" y1="12.5" x2="17.5" y2="10.5"></line><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="15.5" y1="12.5" x2="15.5" y2="9.5"></line><line fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round" x1="13.5" y1="12.5" x2="13.5" y2="11"></line></g></svg>');

            $nav->create(ucfirst(__('Calendar')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reservations.calendar')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="h-4 w-4 text-grey-80 group-hover:text-blue"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="10" d="M15.5 14.5c0 .6-.4 1-1 1h-13c-.6 0-1-.4-1-1v-11c0-.6.4-1 1-1h13c.6 0 1 .4 1 1v11zm-15-8h15M4.5 4V.5m7 3.5V.5"></path></svg>');

            if (config('resrv-config.enable_locations', true)) {
                $nav->create(ucfirst(__('Locations')))
                    ->section('Resrv')
                    ->can(auth()->user()->can('use resrv'))
                    ->route('resrv.locations.index')
                    ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M8.500 7.000 A3.500 3.500 0 1 0 15.500 7.000 A3.500 3.500 0 1 0 8.500 7.000 Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M12,.5a6.856,6.856,0,0,1,6.855,6.856c0,3.215-4.942,11.185-6.434,13.517a.5.5,0,0,1-.842,0c-1.492-2.332-6.434-10.3-6.434-13.517A6.855,6.855,0,0,1,12,.5Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M17,19.7c3.848.324,6.5,1.009,6.5,1.8,0,1.105-5.148,2-11.5,2S.5,22.605.5,21.5c0-.79,2.635-1.473,6.458-1.8" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');
            }

            $nav->create(ucfirst(__('Extras')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.extras.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M5.5,7h13a5,5,0,0,1,5,5h0a5,5,0,0,1-5,5H5.5a5,5,0,0,1-5-5h0A5,5,0,0,1,5.5,7Z" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18 9.501L18 14.501" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 9.501L16 14.501" fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

            $nav->create(ucfirst(__('Dynamic Pricing')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.dynamicpricings.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(1,0,0,1,0,0)"><path d="M8 16L16 8" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M7.500 9.000 A1.500 1.500 0 1 0 10.500 9.000 A1.500 1.500 0 1 0 7.500 9.000 Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.500 15.000 A1.500 1.500 0 1 0 16.500 15.000 A1.500 1.500 0 1 0 13.500 15.000 Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,21.5v1a1,1,0,0,0,1,1h1" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5,23.5h1a1,1,0,0,0,1-1v-1" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,2.5v-1a1,1,0,0,1,1-1h1" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5.5h1a1,1,0,0,1,1,1v1" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 0.5L13.5 0.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 0.5L8 0.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 0.5L19 0.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 23.5L13.5 23.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 23.5L8 23.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 23.5L19 23.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 10.5L23.5 13.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 5L23.5 8" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 16L23.5 19" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 10.5L0.5 13.5" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 5L0.5 8" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 16L0.5 19" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

            $nav->create(ucfirst(__('Import')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.dataimport.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M.752 2.251a1.5 1.5 0 0 1 1.5-1.5m0 22.5a1.5 1.5 0 0 1-1.5-1.5m22.5 0a1.5 1.5 0 0 1-1.5 1.5m0-22.5a1.5 1.5 0 0 1 1.5 1.5m0 15.75v-1.5m0-3.75v-1.5m0-3.75v-1.5m-22.5 12v-1.5m0-3.75v-1.5m0-3.75v-1.5m5.25-5.25h1.5m3.75 0h1.5m3.75 0h1.5m-12 22.5h1.5m3.75 0h1.5m3.75 0h1.5m-6-5.25v-12m4.5 4.5-4.5-4.5-4.5 4.5"></path></svg>');
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

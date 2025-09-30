<?php

namespace Reach\StatamicResrv\Providers;

use Edalzell\Forma\ConfigController;
use Edalzell\Forma\Forma;
use Illuminate\Console\Application as Artisan;
use Reach\StatamicResrv\Events\AvailabilityChanged;
use Reach\StatamicResrv\Events\AvailabilitySearch;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Filters\ReservationEntry;
use Reach\StatamicResrv\Filters\ReservationMadeDate;
use Reach\StatamicResrv\Filters\ReservationStartingDate;
use Reach\StatamicResrv\Filters\ReservationStartingDateYear;
use Reach\StatamicResrv\Filters\ReservationStatus;
use Reach\StatamicResrv\Http\Middleware\SetResrvAffiliateCookie;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Listeners\AddAffiliateToReservation;
use Reach\StatamicResrv\Listeners\AddDynamicPricingsToReservation;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\AddResrvEntryToDatabase;
use Reach\StatamicResrv\Listeners\AssociateAffiliateFromCoupon;
use Reach\StatamicResrv\Listeners\CancelReservation;
use Reach\StatamicResrv\Listeners\ClearAvailabilityFieldCache;
use Reach\StatamicResrv\Listeners\ConfirmReservation;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
use Reach\StatamicResrv\Listeners\EntryDeleted;
use Reach\StatamicResrv\Listeners\IncreaseAvailability;
use Reach\StatamicResrv\Listeners\SaveSearchToSession;
use Reach\StatamicResrv\Listeners\SendNewReservationEmails;
use Reach\StatamicResrv\Listeners\SendRefundReservationEmails;
use Reach\StatamicResrv\Listeners\SoftDeleteResrvEntryFromDatabase;
use Reach\StatamicResrv\Listeners\UpdateConnectedAvailabilities;
use Reach\StatamicResrv\Listeners\UpdateCouponAppliedToReservation;
use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Traits\HandlesAvailabilityHooks;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ResrvProvider extends AddonServiceProvider
{
    use HandlesAvailabilityHooks;

    protected $routes = [
        'cp' => __DIR__.'/../../routes/cp.php',
        'web' => __DIR__.'/../../routes/web.php',
    ];

    protected $middlewareGroups = [
        'statamic.web' => [
            SetResrvAffiliateCookie::class,
        ],
    ];

    protected $commands = [
        \Reach\StatamicResrv\Console\Commands\InstallResrv::class,
        \Reach\StatamicResrv\Console\Commands\ImportEntries::class,
    ];

    protected $dictionaries = [
        \Reach\StatamicResrv\Dictionaries\CountryPhoneCodes::class,
    ];

    protected $fieldtypes = [
        \Reach\StatamicResrv\Fieldtypes\ResrvAvailability::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvOptions::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvExtras::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvFixedPricing::class,
        \Reach\StatamicResrv\Fieldtypes\ResrvCutoff::class,
    ];

    protected $tags = [
        \Reach\StatamicResrv\Tags\Resrv::class,
        \Reach\StatamicResrv\Tags\ResrvCheckoutRedirect::class,
    ];

    protected $scopes = [
        ReservationMadeDate::class,
        ReservationEntry::class,
        ReservationStartingDate::class,
        ReservationStartingDateYear::class,
        ReservationStatus::class,
        ResrvSearch::class,
    ];

    protected $listen = [
        ReservationCreated::class => [
            AddAffiliateToReservation::class,
            AddDynamicPricingsToReservation::class,
            AddReservationIdToSession::class,
            DecreaseAvailability::class,
        ],
        ReservationExpired::class => [
            IncreaseAvailability::class,
        ],
        ReservationConfirmed::class => [
            ConfirmReservation::class,
            SendNewReservationEmails::class,
        ],
        ReservationCancelled::class => [
            CancelReservation::class,
            IncreaseAvailability::class,
        ],
        ReservationRefunded::class => [
            SendRefundReservationEmails::class,
            IncreaseAvailability::class,
        ],
        CouponUpdated::class => [
            UpdateCouponAppliedToReservation::class,
            AssociateAffiliateFromCoupon::class,
        ],
        AvailabilitySearch::class => [
            SaveSearchToSession::class,
        ],
        AvailabilityChanged::class => [
            UpdateConnectedAvailabilities::class,
        ],
        \Statamic\Events\EntrySaved::class => [
            AddResrvEntryToDatabase::class,
        ],
        \Statamic\Events\EntryDeleted::class => [
            EntryDeleted::class,
            SoftDeleteResrvEntryFromDatabase::class,
        ],
        \Statamic\Events\BlueprintSaved::class => [
            ClearAvailabilityFieldCache::class,
        ],
    ];

    protected $vite = [
        'input' => [
            'resources/js/resrv.js',
            'resources/css/resrv.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../../resources/dist/hot',
    ];

    protected $publishables = [
        __DIR__.'/../../resources/frontend' => 'frontend',
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'statamic-resrv');

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'statamic-resrv');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('resrv-config.php'),
        ], 'resrv-config');

        $this->publishes([
            __DIR__.'/../../resources/views/livewire' => resource_path('views/vendor/statamic-resrv/livewire'),
        ], 'resrv-checkout-views');

        $this->publishes([
            __DIR__.'/../../resources/blueprints' => resource_path('blueprints'),
        ], 'resrv-blueprints');

        $this->publishes([
            __DIR__.'/../../resources/forms' => resource_path('forms'),
        ], 'resrv-forms');

        $this->publishes([
            __DIR__.'/../../resources/lang' => lang_path('vendor/statamic-resrv'),
        ], 'resrv-language');

        $this->publishes([
            __DIR__.'/../../resources/views/email' => resource_path('views/vendor/statamic-resrv/email'),
        ], 'resrv-emails');

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'resrv-config');

        $this->app->bind(PaymentInterface::class, config('resrv-config.payment_gateway'));

        if (app()->environment() == 'testing') {
            $this->app->bind(PaymentInterface::class, \Reach\StatamicResrv\Http\Payment\FakePaymentGateway::class);
        }

        $this->createNavigation();

        Forma::add('reachweb/statamic-resrv', ConfigController::class, 'resrv-config');

        $this->bootPermissions();

        $this->bootHooks();

        // Register commands if running in console
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands($this->commands);
        });
    }

    private function createNavigation(): void
    {
        Nav::extend(function ($nav) {
            $nav->create(ucfirst(__('Reservations')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reservations.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" class="text-grey-80 group-hover:text-blue" xmlns="http://www.w3.org/2000/svg">,,,,<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M18.018 17.562a2 2 0 0 0 .482-1.3V5.5h-13v15h9.08a2 2 0 0 0 1.519-.7Z"/><path d="M20.5 2.5h-5.448a3.329 3.329 0 0 0-6.1 0H3.5a1 1 0 0 0-1 1v19a1 1 0 0 0 1 1h17a1 1 0 0 0 1-1v-19a1 1 0 0 0-1-1ZM15.5 8.5h-7M15.5 12.5h-7M13 16.5H8.5"/></g></svg>');

            $nav->create(ucfirst(__('Reports')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reports.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g transform="matrix(1,0,0,1,0,0)"><defs></defs><circle fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" cx="7" cy="8.5" r="3.5"></circle><polyline fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" points="7 5 7 8.5 10.5 8.5"></polyline><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M9,22.5a6.979,6.979,0,0,0,1.5-4"></path><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M15,22.5a6.979,6.979,0,0,1-1.5-4"></path><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="7.499" y1="22.5" x2="16.499" y2="22.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="0.5" y1="15.5" x2="23.5" y2="15.5"></line><rect fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x="0.5" y="1.5" width="23" height="17" rx="1" ry="1"></rect><polyline fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" points="13.5 7 15 5 18 7.5 20.5 4.5"></polyline><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="19.5" y1="12.5" x2="19.5" y2="11"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="17.5" y1="12.5" x2="17.5" y2="10.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="15.5" y1="12.5" x2="15.5" y2="9.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="13.5" y1="12.5" x2="13.5" y2="11"></line></g></svg>');

            $nav->create(ucfirst(__('Calendar')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.reservations.calendar')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="h-4 w-4 text-grey-80 group-hover:text-blue"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="10" d="M15.5 14.5c0 .6-.4 1-1 1h-13c-.6 0-1-.4-1-1v-11c0-.6.4-1 1-1h13c.6 0 1 .4 1 1v11zm-15-8h15M4.5 4V.5m7 3.5V.5"></path></svg>');

            if (config('resrv-config.enable_affiliates', true)) {
                $nav->create(ucfirst(__('Affiliates')))
                    ->section('Resrv')
                    ->can(auth()->user()->can('use resrv'))
                    ->route('resrv.affiliates.index')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" class="text-grey-80 group-hover:text-blue" viewBox="-0.75 -0.75 36 36" height="24" width="24"><defs></defs><path d="m24.4375 10.091249999999999 4.4677500000000006 -4.436125" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.03125 3.59375a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m25.820375 25.8448125 3.0403125 3.059" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.045625 30.90625a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M10.0625 10.091249999999999 5.5961875 5.655125" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.71875 3.59375a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m8.6810625 25.8448125 -3.04175 3.059" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.704375 30.90625a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m23.71875 16.53125 4.3125 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.03125 16.53125a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m10.78125 16.53125 -4.3125 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.71875 16.53125a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M10.795625 23.71875a6.46875 6.46875 0 0 1 12.9375 0Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M13.31125 11.859375a3.953125 3.953125 0 1 0 7.90625 0 3.953125 3.953125 0 1 0 -7.90625 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path></svg>');
            }

            $nav->create(ucfirst(__('Extras')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.extras.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M5.5,7h13a5,5,0,0,1,5,5h0a5,5,0,0,1-5,5H5.5a5,5,0,0,1-5-5h0A5,5,0,0,1,5.5,7Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18 9.501L18 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 9.501L16 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

            $nav->create(ucfirst(__('Dynamic Pricing')))
                ->section('Resrv')
                ->can(auth()->user()->can('use resrv'))
                ->route('resrv.dynamicpricings.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(1,0,0,1,0,0)"><path d="M8 16L16 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M7.500 9.000 A1.500 1.500 0 1 0 10.500 9.000 A1.500 1.500 0 1 0 7.500 9.000 Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.500 15.000 A1.500 1.500 0 1 0 16.500 15.000 A1.500 1.500 0 1 0 13.500 15.000 Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,21.5v1a1,1,0,0,0,1,1h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5,23.5h1a1,1,0,0,0,1-1v-1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,2.5v-1a1,1,0,0,1,1-1h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5.5h1a1,1,0,0,1,1,1v1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 0.5L13.5 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 0.5L8 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 0.5L19 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 23.5L13.5 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 23.5L8 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 23.5L19 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 10.5L23.5 13.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 5L23.5 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 16L23.5 19" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 10.5L0.5 13.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 5L0.5 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 16L0.5 19" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

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

    protected function bootHooks(): void
    {
        $this->bootEntriesHooks('fetched-entries', function ($hookName, $callback) {
            \Statamic\Tags\Collection\Collection::hook($hookName, $callback);
        });
    }
}

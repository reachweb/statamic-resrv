<?php

namespace Reach\StatamicResrv\Providers;

use Illuminate\Console\Application as Artisan;
use Reach\StatamicResrv\Console\Commands\ImportEntries;
use Reach\StatamicResrv\Console\Commands\InstallResrv;
use Reach\StatamicResrv\Console\Commands\MigrateSettings;
use Reach\StatamicResrv\Console\Commands\SendAbandonedReservationEmails;
use Reach\StatamicResrv\Console\Commands\UpgradeToRates;
use Reach\StatamicResrv\Dictionaries\CountryPhoneCodes;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Fieldtypes\ResrvAvailability;
use Reach\StatamicResrv\Fieldtypes\ResrvCutoff;
use Reach\StatamicResrv\Fieldtypes\ResrvEmailTemplate;
use Reach\StatamicResrv\Fieldtypes\ResrvExtras;
use Reach\StatamicResrv\Fieldtypes\ResrvFixedPricing;
use Reach\StatamicResrv\Fieldtypes\ResrvOptions;
use Reach\StatamicResrv\Filters\ReservationEntry;
use Reach\StatamicResrv\Filters\ReservationMadeDate;
use Reach\StatamicResrv\Filters\ReservationStartingDate;
use Reach\StatamicResrv\Filters\ReservationStartingDateYear;
use Reach\StatamicResrv\Filters\ReservationStatus;
use Reach\StatamicResrv\Helpers\DataImport;
use Reach\StatamicResrv\Http\Middleware\SetResrvAffiliateCookie;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Listeners\AddAffiliateToReservation;
use Reach\StatamicResrv\Listeners\AddDynamicPricingsToReservation;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\AddResrvEntryToDatabase;
use Reach\StatamicResrv\Listeners\AssociateAffiliateFromCoupon;
use Reach\StatamicResrv\Listeners\ClearAvailabilityFieldCache;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
use Reach\StatamicResrv\Listeners\EntryDeleted;
use Reach\StatamicResrv\Listeners\IncreaseAvailability;
use Reach\StatamicResrv\Listeners\NormalizeAvailabilityFieldValue;
use Reach\StatamicResrv\Listeners\PreventEntryDeletionWithActiveReservations;
use Reach\StatamicResrv\Listeners\SendNewReservationEmails;
use Reach\StatamicResrv\Listeners\SendRefundReservationEmails;
use Reach\StatamicResrv\Listeners\SoftDeleteResrvEntryFromDatabase;
use Reach\StatamicResrv\Listeners\UpdateCouponAppliedToReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Support\SettingsBlueprint;
use Reach\StatamicResrv\Support\SettingsMigrator;
use Reach\StatamicResrv\Tags\Resrv;
use Reach\StatamicResrv\Tags\ResrvCheckoutRedirect;
use Reach\StatamicResrv\Traits\HandlesAvailabilityHooks;
use Reach\StatamicResrv\UpdateScripts\MigrateConfigToSettings;
use Statamic\Events\BlueprintSaved;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\YAML;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Tags\Collection\Collection;

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
        InstallResrv::class,
        ImportEntries::class,
        MigrateSettings::class,
        UpgradeToRates::class,
        SendAbandonedReservationEmails::class,
    ];

    /** Not auto-discovered — Statamic only scans src/Providers/UpdateScripts. */
    protected $updateScripts = [
        MigrateConfigToSettings::class,
    ];

    protected $dictionaries = [
        CountryPhoneCodes::class,
    ];

    protected $fieldtypes = [
        ResrvAvailability::class,
        ResrvOptions::class,
        ResrvExtras::class,
        ResrvFixedPricing::class,
        ResrvCutoff::class,
        ResrvEmailTemplate::class,
    ];

    protected $tags = [
        Resrv::class,
        ResrvCheckoutRedirect::class,
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
            DecreaseAvailability::class,
            AddReservationIdToSession::class,
        ],
        ReservationExpired::class => [
            IncreaseAvailability::class,
        ],
        ReservationConfirmed::class => [
            SendNewReservationEmails::class,
        ],
        ReservationCancelled::class => [
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
        EntrySaving::class => [
            NormalizeAvailabilityFieldValue::class,
        ],
        EntrySaved::class => [
            AddResrvEntryToDatabase::class,
        ],
        EntryDeleting::class => [
            PreventEntryDeletionWithActiveReservations::class,
        ],
        \Statamic\Events\EntryDeleted::class => [
            EntryDeleted::class,
            SoftDeleteResrvEntryFromDatabase::class,
        ],
        BlueprintSaved::class => [
            ClearAvailabilityFieldCache::class,
        ],
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../../resources/dist/hot',
    ];

    protected $publishables = [
        __DIR__.'/../../resources/frontend' => 'frontend',
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PaymentGatewayManager::class, fn () => new PaymentGatewayManager);

        $this->registerSerializableCacheClasses();
    }

    public function bootAddon(): void
    {
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

        $blueprint = YAML::file(__DIR__.'/../../resources/blueprints/settings.yaml')->parse();
        $blueprintFields = SettingsBlueprint::fields($blueprint);

        $this->registerSettingsBlueprint(
            $this->injectPublishedConfigWarning($blueprint, $blueprintFields)
        );

        $this->mergeAddonSettings($blueprintFields);

        $this->app->bind(
            PaymentInterface::class,
            app()->environment('testing')
                ? FakePaymentGateway::class
                : config('resrv-config.payment_gateway')
        );

        $this->createNavigation();

        $this->bootPermissions();

        $this->bootHooks();

        $this->app->terminating(fn () => Rate::resetEntryCollectionCache());

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
                ->can('use resrv')
                ->route('resrv.reservations.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" class="text-grey-80 group-hover:text-blue" xmlns="http://www.w3.org/2000/svg">,,,,<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M18.018 17.562a2 2 0 0 0 .482-1.3V5.5h-13v15h9.08a2 2 0 0 0 1.519-.7Z"/><path d="M20.5 2.5h-5.448a3.329 3.329 0 0 0-6.1 0H3.5a1 1 0 0 0-1 1v19a1 1 0 0 0 1 1h17a1 1 0 0 0 1-1v-19a1 1 0 0 0-1-1ZM15.5 8.5h-7M15.5 12.5h-7M13 16.5H8.5"/></g></svg>');

            $nav->create(ucfirst(__('Reports')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.reports.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g transform="matrix(1,0,0,1,0,0)"><defs></defs><circle fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" cx="7" cy="8.5" r="3.5"></circle><polyline fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" points="7 5 7 8.5 10.5 8.5"></polyline><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M9,22.5a6.979,6.979,0,0,0,1.5-4"></path><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M15,22.5a6.979,6.979,0,0,1-1.5-4"></path><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="7.499" y1="22.5" x2="16.499" y2="22.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="0.5" y1="15.5" x2="23.5" y2="15.5"></line><rect fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x="0.5" y="1.5" width="23" height="17" rx="1" ry="1"></rect><polyline fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" points="13.5 7 15 5 18 7.5 20.5 4.5"></polyline><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="19.5" y1="12.5" x2="19.5" y2="11"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="17.5" y1="12.5" x2="17.5" y2="10.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="15.5" y1="12.5" x2="15.5" y2="9.5"></line><line fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" x1="13.5" y1="12.5" x2="13.5" y2="11"></line></g></svg>');

            $nav->create(ucfirst(__('Calendar')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.reservations.calendar')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="h-4 w-4 text-grey-80 group-hover:text-blue"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="10" d="M15.5 14.5c0 .6-.4 1-1 1h-13c-.6 0-1-.4-1-1v-11c0-.6.4-1 1-1h13c.6 0 1 .4 1 1v11zm-15-8h15M4.5 4V.5m7 3.5V.5"></path></svg>');

            if (config('resrv-config.enable_affiliates', true)) {
                $nav->create(ucfirst(__('Affiliates')))
                    ->section('Resrv')
                    ->can('use resrv')
                    ->route('resrv.affiliates.index')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" class="text-grey-80 group-hover:text-blue" viewBox="-0.75 -0.75 36 36" height="24" width="24"><defs></defs><path d="m24.4375 10.091249999999999 4.4677500000000006 -4.436125" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.03125 3.59375a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m25.820375 25.8448125 3.0403125 3.059" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.045625 30.90625a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M10.0625 10.091249999999999 5.5961875 5.655125" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.71875 3.59375a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m8.6810625 25.8448125 -3.04175 3.059" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.704375 30.90625a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m23.71875 16.53125 4.3125 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M28.03125 16.53125a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="m10.78125 16.53125 -4.3125 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M0.71875 16.53125a2.875 2.875 0 1 0 5.75 0 2.875 2.875 0 1 0 -5.75 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M10.795625 23.71875a6.46875 6.46875 0 0 1 12.9375 0Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path><path d="M13.31125 11.859375a3.953125 3.953125 0 1 0 7.90625 0 3.953125 3.953125 0 1 0 -7.90625 0" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path></svg>');
            }

            $nav->create(ucfirst(__('Rates')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.rates.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 16L16 8"/><circle cx="9" cy="9" r="1.5"/><circle cx="15" cy="15" r="1.5"/><rect x="2" y="2" width="20" height="20" rx="3"/></g></svg>');

            $nav->create(ucfirst(__('Extras')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.extras.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M5.5,7h13a5,5,0,0,1,5,5h0a5,5,0,0,1-5,5H5.5a5,5,0,0,1-5-5h0A5,5,0,0,1,5.5,7Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18 9.501L18 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 9.501L16 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

            $nav->create(ucfirst(__('Dynamic Pricing')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.dynamicpricings.index')
                ->icon('<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(1,0,0,1,0,0)"><path d="M8 16L16 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M7.500 9.000 A1.500 1.500 0 1 0 10.500 9.000 A1.500 1.500 0 1 0 7.500 9.000 Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.500 15.000 A1.500 1.500 0 1 0 16.500 15.000 A1.500 1.500 0 1 0 13.500 15.000 Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,21.5v1a1,1,0,0,0,1,1h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5,23.5h1a1,1,0,0,0,1-1v-1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M.5,2.5v-1a1,1,0,0,1,1-1h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.5.5h1a1,1,0,0,1,1,1v1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 0.5L13.5 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 0.5L8 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 0.5L19 0.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M10.5 23.5L13.5 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5 23.5L8 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 23.5L19 23.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 10.5L23.5 13.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 5L23.5 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M23.5 16L23.5 19" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 10.5L0.5 13.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 5L0.5 8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 16L0.5 19" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>');

            $nav->create(ucfirst(__('Import')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.dataimport.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" transform="rotate(180 12 12)"><path d="M12 15.75V3.75"/><path d="m7.5 11.25 4.5 4.5 4.5-4.5"/><path d="M21 17.25v3a.75.75 0 0 1-.75.75H3.75A.75.75 0 0 1 3 20.25v-3"/></g></svg>');

            $nav->create(ucfirst(__('Export')))
                ->section('Resrv')
                ->can('use resrv')
                ->route('resrv.export.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M12 15.75V3.75"/><path d="m7.5 11.25 4.5 4.5 4.5-4.5"/><path d="M21 17.25v3a.75.75 0 0 1-.75.75H3.75A.75.75 0 0 1 3 20.25v-3"/></g></svg>');

            if ($addon = Addon::get('reachweb/statamic-resrv')) {
                $nav->create(ucfirst(__('Settings')))
                    ->section('Resrv')
                    ->can('configure addons')
                    ->url($addon->settingsUrl())
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" height="24" width="24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></g></svg>');
            }
        });
    }

    /**
     * Assemble the effective resrv-config: blueprint defaults, overlaid by anything
     * already in config (developer config file, test overrides), overlaid by the
     * CP-saved settings. raw() avoids Settings::resolveAntlersValue() stringifying
     * booleans/integers. Legacy "no logo" sentinels (false, 'false', '') are
     * normalized to null here so runtime consumers only ever see null or a URL.
     */
    protected function mergeAddonSettings(array $blueprintFields): void
    {
        $addon = Addon::get('reachweb/statamic-resrv');

        if (! $addon || ! $addon->hasSettingsBlueprint()) {
            return;
        }

        $merged = array_merge(
            SettingsBlueprint::defaultsFromFields($blueprintFields),
            config('resrv-config'),
            $addon->settings()->raw()
        );

        if (isset($merged['logo']) && in_array($merged['logo'], [false, 'false', ''], true)) {
            $merged['logo'] = null;
        }

        config(['resrv-config' => $merged]);
    }

    /**
     * Prepend a warning section to the settings blueprint when a published
     * config/resrv-config.php still defines CP-managed keys, since values saved in
     * the CP silently override that file at runtime.
     */
    protected function injectPublishedConfigWarning(array $blueprint, array $blueprintFields): array
    {
        if (($published = SettingsMigrator::publishedConfig()) === null) {
            return $blueprint;
        }

        $shadowed = array_keys(array_intersect_key($published, $blueprintFields));

        if (empty($shadowed)) {
            return $blueprint;
        }

        $firstTab = array_key_first($blueprint['tabs'] ?? []);

        if ($firstTab === null) {
            return $blueprint;
        }

        array_unshift($blueprint['tabs'][$firstTab]['sections'], [
            'display' => '⚠ Published config file detected',
            'instructions' => 'Your `config/resrv-config.php` defines: **'.implode('**, **', $shadowed).'**. '
                .'Values saved on this page override that file at runtime. '
                .'Remove those keys from the file, or run `php please resrv:settings:migrate`.',
            'fields' => [],
        ]);

        return $blueprint;
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
            Collection::hook($hookName, $callback);
        });
    }

    /**
     * Allow-list the pure-data classes Resrv caches (Collection<stdClass> pricing rows, the
     * DataImport object) so Laravel 13's `cache.serializable_classes` hardening doesn't
     * return them as __PHP_Incomplete_Class on warm reads.
     *
     * Runs in register(), before the cache store is built. registerSerializableClasses()
     * is additive and a no-op when the host hasn't enabled hardening.
     */
    protected function registerSerializableCacheClasses(): void
    {
        $this->registerSerializableClasses([
            \Illuminate\Support\Collection::class,
            \stdClass::class,
            DataImport::class,
        ]);
    }
}

<?php

namespace Reach\StatamicResrv\Providers;

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection;
use Reach\StatamicResrv\Livewire\AvailabilityControl;
use Reach\StatamicResrv\Livewire\AvailabilityList;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\CheckoutForm;
use Reach\StatamicResrv\Livewire\CheckoutPayment;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Livewire\LfAvailabilityFilter;
use Reach\StatamicResrv\Livewire\Options;
use Reach\StatamicResrv\Traits\HandlesAvailabilityHooks;
use Statamic\Providers\AddonServiceProvider;

class ResrvLivewireProvider extends AddonServiceProvider
{
    use HandlesAvailabilityHooks;

    public function boot(): void
    {
        // Define the base path for overridden views
        $overridePath = resource_path('views/vendor/statamic-resrv/livewire/components');

        // Define the default package view path
        $packagePath = __DIR__.'/../../resources/views/livewire/components';

        // Register override path first so it takes priority, then always register
        // the package path as fallback for any components not overridden by the user
        if (is_dir($overridePath)) {
            Blade::anonymousComponentPath($overridePath, 'resrv');
        }
        Blade::anonymousComponentPath($packagePath, 'resrv');

        $this->bootLivewireComponents();

        $this->bootHooks();
    }

    protected function bootLivewireComponents(): void
    {
        Livewire::component('availability-search', AvailabilitySearch::class);
        Livewire::component('availability-results', AvailabilityResults::class);
        Livewire::component('availability-list', AvailabilityList::class);
        Livewire::component('availability-control', AvailabilityControl::class);
        Livewire::component('extras', Extras::class);
        Livewire::component('options', Options::class);
        Livewire::component('checkout', Checkout::class);
        Livewire::component('checkout-form', CheckoutForm::class);
        Livewire::component('checkout-payment', CheckoutPayment::class);
        if (class_exists(LivewireCollection::class)) {
            Livewire::component('lf-availability-filter', LfAvailabilityFilter::class);
        }
    }

    protected function bootHooks(): void
    {
        if (! class_exists(LivewireCollection::class)) {
            return;
        }

        $this->bootEntriesHooks('livewire-fetched-entries', function ($hookName, $callback) {
            LivewireCollection::hook($hookName, $callback);
        });
    }
}

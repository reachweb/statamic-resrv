<?php

namespace Reach\StatamicResrv\Providers;

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
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
        Livewire::component('availability-search', \Reach\StatamicResrv\Livewire\AvailabilitySearch::class);
        Livewire::component('availability-results', \Reach\StatamicResrv\Livewire\AvailabilityResults::class);
        Livewire::component('availability-list', \Reach\StatamicResrv\Livewire\AvailabilityList::class);
        Livewire::component('availability-control', \Reach\StatamicResrv\Livewire\AvailabilityControl::class);
        Livewire::component('extras', \Reach\StatamicResrv\Livewire\Extras::class);
        Livewire::component('options', \Reach\StatamicResrv\Livewire\Options::class);
        Livewire::component('checkout', \Reach\StatamicResrv\Livewire\Checkout::class);
        Livewire::component('checkout-form', \Reach\StatamicResrv\Livewire\CheckoutForm::class);
        Livewire::component('checkout-payment', \Reach\StatamicResrv\Livewire\CheckoutPayment::class);
        if (class_exists(\Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::class)) {
            Livewire::component('lf-availability-filter', \Reach\StatamicResrv\Livewire\LfAvailabilityFilter::class);
        }
    }

    protected function bootHooks(): void
    {
        if (! class_exists(\Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::class)) {
            return;
        }

        $this->bootEntriesHooks('livewire-fetched-entries', function ($hookName, $callback) {
            \Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::hook($hookName, $callback);
        });
    }
}

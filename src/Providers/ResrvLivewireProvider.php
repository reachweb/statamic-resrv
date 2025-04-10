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

        // Use the override path if it exists; otherwise, use the package's default path
        $viewPath = is_dir($overridePath) ? $overridePath : $packagePath;

        Blade::anonymousComponentPath($viewPath, 'resrv');

        $this->bootLivewireComponents();

        $this->bootHooks();
    }

    protected function bootLivewireComponents(): void
    {
        Livewire::component('availability-search', \Reach\StatamicResrv\Livewire\AvailabilitySearch::class);
        Livewire::component('availability-results', \Reach\StatamicResrv\Livewire\AvailabilityResults::class);
        Livewire::component('availability-control', \Reach\StatamicResrv\Livewire\AvailabilityControl::class);
        Livewire::component('extras-options', \Reach\StatamicResrv\Livewire\ExtrasOptions::class);
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

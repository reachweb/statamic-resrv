<?php

namespace Reach\StatamicResrv\Providers;

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
use Statamic\Providers\AddonServiceProvider;

class ResrvLivewireProvider extends AddonServiceProvider
{
    use HandlesAvailabilityQueries;

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

    private function bootLivewireComponents(): void
    {
        Livewire::component('availability-search', \Reach\StatamicResrv\Livewire\AvailabilitySearch::class);
        Livewire::component('availability-results', \Reach\StatamicResrv\Livewire\AvailabilityResults::class);
        Livewire::component('checkout', \Reach\StatamicResrv\Livewire\Checkout::class);
        Livewire::component('checkout-form', \Reach\StatamicResrv\Livewire\CheckoutForm::class);
        Livewire::component('checkout-payment', \Reach\StatamicResrv\Livewire\CheckoutPayment::class);
        if (class_exists(\Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::class)) {
            Livewire::component('lf-availability-filter', \Reach\StatamicResrv\Livewire\LfAvailabilityFilter::class);
        }
    }

    private function bootHooks(): void
    {
        if (! class_exists(\Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::class)) {
            return;
        }
        $instance = $this;
        \Reach\StatamicLivewireFilters\Http\Livewire\LivewireCollection::hook('livewire-fetched-entries',
            function ($entries, $next) use ($instance) {
                $searchData = $instance->availabilitySearchData($this->params);

                if ($searchData->isEmpty()) {
                    return $next($entries);
                }

                $result = $instance->getAvailability($searchData);

                if (data_get($result, 'message.status') === false) {
                    return $next($entries);
                }

                $entries->each(function ($entry) use ($result) {
                    if ($data = data_get($result, 'data.'.$entry->id(), false)) {
                        if ($data->count() === 1) {
                            $data = $data->first();
                        }

                        $entry->set('live_availability', $data);
                    }
                });

                return $next($entries);
            }
        );
    }
}

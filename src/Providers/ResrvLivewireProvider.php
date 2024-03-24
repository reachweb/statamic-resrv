<?php

namespace Reach\StatamicResrv\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Traits\HandlesAvailabilityQueries;
use Statamic\Providers\AddonServiceProvider;

class ResrvLivewireProvider extends AddonServiceProvider
{
    use HandlesAvailabilityQueries;

    public function boot(): void
    {
        Blade::anonymousComponentPath(__DIR__.'/../../resources/views/livewire/components', 'resrv');

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

                if (Arr::has($result, 'message.status') && data_get($result, 'message.status') === false) {
                    return $next($entries);
                }

                $entries->each(function ($entry) use ($result) {
                    if ($data = data_get($result, $entry->id, false)) {
                        $entry->set('live_availability', $data);
                    }
                });

                return $next($entries);
            }
        );
    }
}

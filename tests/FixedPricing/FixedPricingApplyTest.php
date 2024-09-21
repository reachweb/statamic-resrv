<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class FixedPricingApplyTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    public $date;

    public $entry;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entry = $this->makeStatamicItem();
        $this->travelTo(today()->setHour(11));

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    public function test_fixed_pricing_changes_availability_prices()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 3, 'none', 7);

        // Baseline price
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(4, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertViewHas('availability.data.price', '100.92');

        // Add fixed pricing
        FixedPricing::create([
            'statamic_id' => $this->entry->id(),
            'days' => 4,
            'price' => 90,
        ]);

        Cache::flush();

        // Check fixed price for 4 days
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(4, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertViewHas('availability.data.price', '90.00');

        // Check for extra days
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(6, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertViewHas('availability.data.price', '151.38');

        // Add extra days fixed pricing
        FixedPricing::create([
            'statamic_id' => $this->entry->id(),
            'days' => 0,
            'price' => 20,
        ]);

        Cache::flush();

        // Check the extra day fixed pricing
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(6, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertViewHas('availability.data.price', '130.00');

        // Check it works for multiple items
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(6, 'day')->toISOString(),
                ],
                'quantity' => 3,
                'advanced' => null,
            ])
            ->assertViewHas('availability.data.price', '390.00');
    }

    public function test_fixed_pricing_changes_reservation_prices()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 3, 'none', 7);

        FixedPricing::create([
            'statamic_id' => $this->entry->id(),
            'days' => 4,
            'price' => 90,
        ]);

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(4, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ]);

        $component->assertViewHas('availability.data.price', '90.00');

        $availability = $component->viewData('availability');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $this->entry->id(),
            'date_start' => $this->date,
            'date_end' => $this->date->copy()->add(4, 'day'),
            'quantity' => 1,
            'payment' => data_get($availability, 'data.payment'),
            'price' => 90.00,
        ]);

        // Test extra days fixed pricing
        FixedPricing::create([
            'statamic_id' => $this->entry->id(),
            'days' => 0,
            'price' => 20,
        ]);

        Cache::flush();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(6, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ]);

        $component->assertViewHas('availability.data.price', '130.00');

        $availability = $component->viewData('availability');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $this->entry->id(),
            'date_start' => $this->date,
            'date_end' => $this->date->copy()->add(6, 'day'),
            'quantity' => 1,
            'payment' => data_get($availability, 'data.payment'),
            'price' => 130.00,
        ]);
    }

    public function test_fixed_pricing_changes_reservation_prices_for_multiple_items()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 3, 'none', 7);

        FixedPricing::create([
            'statamic_id' => $this->entry->id(),
            'days' => 4,
            'price' => 90,
        ]);

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(4, 'day')->toISOString(),
                ],
                'quantity' => 3,
                'advanced' => null,
            ]);

        $component->assertViewHas('availability.data.price', '270.00');

        $availability = $component->viewData('availability');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $this->entry->id(),
            'date_start' => $this->date,
            'date_end' => $this->date->copy()->add(4, 'day'),
            'quantity' => 3,
            'payment' => data_get($availability, 'data.payment'),
            'price' => 270.00,
        ]);
    }
}

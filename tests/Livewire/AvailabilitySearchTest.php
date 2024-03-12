<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilitySearchTest extends TestCase
{
    public $date;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->setHour(12);
    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(AvailabilitySearch::class)
            ->assertViewIs('statamic-resrv::livewire.availability-search')
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_dates()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->assertSet('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ]
            )
            ->assertDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    /** @test */
    public function cannot_set_dates_if_date_start_lte_date_end()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date->copy()->add(1, 'day'),
                'date_end' => $this->date,
            ])
            ->assertSet('data.dates',
                [
                    'date_start' => $this->date->copy()->add(1, 'day'),
                    'date_end' => $this->date,
                ]
            )
            ->assertHasErrors(['data.dates.date_start'])
            ->assertHasErrors(['data.dates.date_end'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    /** @test */
    public function cannot_set_dates_before_the_allowed_period()
    {
        Config::set('resrv-config.minimum_days_before', 2);

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->assertSet('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ]
            )
            ->assertHasErrors(['data.dates.date_start'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_quantity()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.quantity', 2)
            ->assertSet('data.quantity', 2)
            ->assertDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_property()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.property', 'something')
            ->assertSet('data.property', 'something')
            ->assertDispatched('availability-search-updated')
            ->assertStatus(200);
    }
}

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
        $this->date = now()->setTime(12, 0, 0);
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
            ->assertDispatched('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertSessionHas('resrv-search');
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
            ->assertDispatched('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 2,
                    'advanced' => null,
                ])
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_advanced()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.advanced', 'something')
            ->assertSet('data.advanced', 'something')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => 'something',
            ])
            ->assertStatus(200);
    }

    /** @test */
    public function sets_search_from_session()
    {
        // This test might look like it does nothing but the first call sets the dates in the session
        // and the second one asserts that they are being retrieved from the session.

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ]
            );

        Livewire::test(AvailabilitySearch::class)
            ->assertSet('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ])->assertStatus(200);
    }
}

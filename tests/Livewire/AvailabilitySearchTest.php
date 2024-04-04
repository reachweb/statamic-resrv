<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades;

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
                    'custom' => [],
                ]
            )
            ->assertSessionHas('resrv-search');
    }

    /** @test */
    public function does_not_dispatch_if_live_is_false_unless_search_is_called()
    {
        Livewire::test(AvailabilitySearch::class, ['live' => false])
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
            ->assertNotDispatched('availability-search-updated')
            ->call('search')
            ->assertDispatched('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                    'custom' => [],
                ]
            );
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
                    'custom' => [],
                ])
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_enable_quantity_property_and_shows_the_control()
    {
        $component = Livewire::test(AvailabilitySearch::class, ['enableQuantity' => true])
            ->assertSet('enableQuantity', true)
            ->assertSee('x-bind:value="quantity"', false);

        $this->assertEquals(config('resrv-config.maximum_quantity'), $component->__get('maxQuantity'));
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
                'custom' => [],
            ])
            ->assertStatus(200);
    }

    /** @test */
    public function cannot_set_advanced_without_dates()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.advanced', 'something-else')
            ->assertSet('data.advanced', 'something-else')
            ->assertHasErrors(['data.dates.date_start'])
            ->assertSee('Availability search requires date information to be provided.')
            ->assertNotDispatched('availability-search-updated')
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->assertSet('data.advanced', 'something-else')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => 'something-else',
                'custom' => [],
            ]);
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

    /** @test */
    public function can_return_advanced_properties_if_set()
    {
        $component = Livewire::test(AvailabilitySearch::class, ['advanced' => true, 'overrideProperties' => ['something']])
            ->assertSet('advanced', true)
            ->assertSet('overrideProperties', ['something'])
            ->assertSee('select');
        $this->assertEquals(['something'], $component->__get('advancedProperties'));
    }

    /** @test */
    public function can_return_advanced_properties_from_blueprint()
    {
        $collection = Facades\Collection::make('cars')->save();

        $blueprint = Facades\Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Title',
                            ],
                        ],
                        [

                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'listable' => 'hidden',
                                'advanced_availability' => [
                                    'location1' => 'Location 1',
                                    'location2' => 'Location 2',
                                    'location3' => 'Location 3',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->setHandle('cars')->setNamespace('collections.'.$collection->handle())->save();

        $component = Livewire::test(AvailabilitySearch::class, ['advanced' => 'cars.cars'])
            ->assertSet('advanced', 'cars.cars');

        $this->assertEquals([
            'location1' => 'Location 1',
            'location2' => 'Location 2',
            'location3' => 'Location 3',
        ], $component->__get('advancedProperties'));
    }

    /** @test */
    public function can_set_a_custom_value()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.custom.adults', 2)
            ->assertSet('data.custom', ['adults' => 2]);
    }
}

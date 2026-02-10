<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades;

class AvailabilitySearchTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilitySearch::class)
            ->assertViewIs('statamic-resrv::livewire.availability-search')
            ->assertStatus(200);
    }

    public function test_can_set_dates()
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
                    'rate' => null,
                    'customer' => [],
                ]
            )
            ->assertSessionHas('resrv-search');
    }

    public function test_cannot_set_dates_closer_than_allowed()
    {
        Config::set('resrv-config.minimum_days_before', 7);

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date->copy()->add(4, 'day'),
                'date_end' => $this->date->copy()->add(5, 'day'),
            ])
            ->assertHasErrors(['data.dates.date_start'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    public function test_cannot_set_dates_before_now()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date->copy()->sub(4, 'day'),
                'date_end' => $this->date->copy()->sub(5, 'day'),
            ])
            ->assertHasErrors(['data.dates.date_start'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    public function test_does_not_dispatch_if_live_is_false_unless_search_is_called()
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
                    'rate' => null,
                    'customer' => [],
                ]
            );
    }

    public function test_cannot_set_dates_if_date_start_lte_date_end()
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

    public function test_cannot_set_dates_before_the_allowed_period()
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

    public function test_cannot_set_dates_with_duration_smaller_than_the_allowed_period()
    {
        Config::set('resrv-config.minimum_reservation_period_in_days', 3);

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
            ->assertHasErrors(['data.dates'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    public function test_cannot_set_dates_with_duration_bigger_than_the_allowed_period()
    {
        Config::set('resrv-config.maximum_reservation_period_in_days', 1);

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(3, 'day'),
            ])
            ->assertSet('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(3, 'day'),
                ]
            )
            ->assertHasErrors(['data.dates'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    public function test_can_set_quantity()
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
                    'rate' => null,
                    'customer' => [],
                ])
            ->assertStatus(200);
    }

    public function test_cannot_set_quantity_greater_than_max_quantity()
    {
        Config::set('resrv-config.maximum_quantity', 2);

        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.quantity', 3)
            ->assertSet('data.quantity', 3)
            ->assertHasErrors(['data.quantity'])
            ->assertNotDispatched('availability-search-updated')
            ->assertStatus(200);
    }

    public function test_can_set_enable_quantity_property_and_shows_the_control()
    {
        $component = Livewire::test(AvailabilitySearch::class, ['enableQuantity' => true])
            ->assertSet('enableQuantity', true)
            ->assertSee('x-bind:value="quantity"', false);

        $this->assertEquals(config('resrv-config.maximum_quantity'), $component->__get('maxQuantity'));
    }

    public function test_can_set_advanced()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.rate', 'something')
            ->assertSet('data.rate', 'something')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'something',
                'customer' => [],
            ])
            ->assertStatus(200);
    }

    public function test_cannot_set_advanced_without_dates()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.rate', 'something-else')
            ->assertSet('data.rate', 'something-else')
            ->assertHasErrors(['data.dates.date_start'])
            ->assertSee('Availability search requires date information to be provided.')
            ->assertNotDispatched('availability-search-updated')
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->assertSet('data.rate', 'something-else')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'something-else',
                'customer' => [],
            ]);
    }

    public function test_sets_search_from_session()
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

    public function test_can_return_advanced_properties_if_set()
    {
        $component = Livewire::test(AvailabilitySearch::class, ['rates' => true, 'overrideRates' => ['something']])
            ->assertSet('rates', true)
            ->assertSet('overrideRates', ['something'])
            ->assertSee('select');
        $this->assertEquals(['something'], $component->__get('entryRates'));
    }

    public function test_can_return_advanced_properties_from_blueprint()
    {
        $collection = Facades\Collection::make('cars')->routes('/{slug}')->save();

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

        $entry = \Statamic\Entries\Entry::make()
            ->collection($collection)
            ->slug('test-car')
            ->data(['title' => 'Test Car']);
        $entry->save();

        $component = Livewire::test(AvailabilitySearch::class, ['rates' => true, 'entry' => $entry->id()])
            ->assertSet('rates', true);

        $this->assertEquals([
            'location1' => 'Location 1',
            'location2' => 'Location 2',
            'location3' => 'Location 3',
        ], $component->__get('entryRates'));
    }

    public function test_can_set_a_custom_value()
    {
        Livewire::test(AvailabilitySearch::class)
            ->set('data.customer.adults', 2)
            ->assertSet('data.customer', ['adults' => 2]);
    }

    public function test_availability_calendar_returns_data_when_enabled()
    {
        $component = Livewire::test(AvailabilitySearch::class, [
            'entry' => $this->entries->first()->id(),
            'showAvailabilityOnCalendar' => true,
        ])
            ->assertSet('showAvailabilityOnCalendar', true)
            ->call('availabilityCalendar');

        $calendar = $component->effects['returns'][0];

        $key = $this->date->format('Y-m-d').' 00:00:00';

        $this->assertIsArray($calendar);
        $this->assertArrayHasKey($key, $calendar);
        $this->assertEquals(50, $calendar[$key]['price']);
        $this->assertEquals(1, $calendar[$key]['available']);
    }

    public function test_availability_calendar_with_advanced_availability()
    {
        $component = Livewire::test(AvailabilitySearch::class, [
            'entry' => $this->advancedEntries->first()->id(),
            'showAvailabilityOnCalendar' => true,
        ])
            ->assertSet('showAvailabilityOnCalendar', true)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.rate', 'test')
            ->call('availabilityCalendar');

        $calendar = $component->effects['returns'][0];

        $key = $this->date->format('Y-m-d').' 00:00:00';

        $this->assertIsArray($calendar);
        $this->assertArrayHasKey($key, $calendar);
        $this->assertEquals(50, $calendar[$key]['price']);
        $this->assertEquals(1, $calendar[$key]['available']);
    }

    public function test_listens_to_availability_date_selected_event()
    {
        Livewire::test(AvailabilitySearch::class)
            ->dispatch('availability-date-selected', [
                'date' => $this->date->copy()->add(5, 'day')->toDateString(),
                'rate_id' => null,
            ])
            ->assertSet('data.dates.date_start', $this->date->copy()->add(5, 'day')->toDateString())
            ->assertSet('data.dates.date_end', $this->date->copy()->add(6, 'day')->toDateString())
            ->assertDispatched('availability-search-updated');
    }

    public function test_availability_date_selected_updates_advanced_property()
    {
        Livewire::test(AvailabilitySearch::class)
            ->dispatch('availability-date-selected', [
                'date' => $this->date->copy()->add(5, 'day')->toDateString(),
                'rate_id' => 'cabin-a',
            ])
            ->assertSet('data.dates.date_start', $this->date->copy()->add(5, 'day')->toDateString())
            ->assertSet('data.rate', 'cabin-a')
            ->assertDispatched('availability-search-updated');
    }

    public function test_availability_date_selected_respects_minimum_reservation_period()
    {
        Config::set('resrv-config.minimum_reservation_period_in_days', 3);

        Livewire::test(AvailabilitySearch::class)
            ->dispatch('availability-date-selected', [
                'date' => $this->date->copy()->add(5, 'day')->toDateString(),
                'rate_id' => null,
            ])
            ->assertSet('data.dates.date_start', $this->date->copy()->add(5, 'day')->toDateString())
            ->assertSet('data.dates.date_end', $this->date->copy()->add(8, 'day')->toDateString())
            ->assertDispatched('availability-search-updated');
    }

    public function test_availability_calendar_returns_lowest_price_when_multiple_properties()
    {
        $entry = $this->entries->first();

        // Create availability with different prices for different properties on the same date
        \Reach\StatamicResrv\Models\Availability::factory()
            ->create([
                'statamic_id' => $entry->id(),
                'date' => today(),
                'price' => 200,
                'available' => 2,
                'property' => 'expensive-cabin',
            ]);

        \Reach\StatamicResrv\Models\Availability::factory()
            ->create([
                'statamic_id' => $entry->id(),
                'date' => today(),
                'price' => 25,
                'available' => 3,
                'property' => 'cheap-cabin',
            ]);

        $component = Livewire::test(AvailabilitySearch::class, [
            'entry' => $entry->id(),
            'showAvailabilityOnCalendar' => true,
        ])
            ->call('availabilityCalendar');

        $calendar = $component->effects['returns'][0];
        $key = today()->format('Y-m-d').' 00:00:00';

        $this->assertIsArray($calendar);
        $this->assertArrayHasKey($key, $calendar);
        // Should return the lowest price (25) not the expensive one (200)
        // Note: The entry already has availability at price 50, so 25 should be returned
        $this->assertEquals(25, $calendar[$key]['price']);
        $this->assertEquals('cheap-cabin', $calendar[$key]['property']);
    }
}

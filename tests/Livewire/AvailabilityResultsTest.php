<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class AvailabilityResultsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-results')
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_extra_days_parameter()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->assertSet('extraDays', 2)
            ->assertStatus(200);
    }

    /** @test */
    public function listens_to_the_availability_search_updated_event()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '100.00')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 2)
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(7, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_zero()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->get('none-available')->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_stop_sales()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->get('stop-sales')->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_quantity_not_enough()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 4,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_availability_for_extra_requested_days()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHasAll([
                'availability.-2',
                'availability.-1',
                'availability.0',
                'availability.+1',
                'availability.+2',
            ])
            ->assertViewHas('availability.-2.message.status', false)
            ->assertViewHas('availability.-1.data.price', '100.00')
            ->assertViewHas('availability.0.data.price', '100.00')
            ->assertViewHas('availability.+1.data.price', '100.00')
            ->assertViewHas('availability.+1.request.date_start', $this->date->copy()->addDays(1)->format('Y-m-d'))
            ->assertViewHas('availability.+2.message.status', false);
    }

    /** @test */
    public function returns_availability_for_extra_requested_days_with_offset()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 1, 'extraDaysOffset' => 1])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHasAll([
                'availability.-1',
                'availability.0',
                'availability.+1',
            ])
            ->assertViewHas('availability.-1.message.status', false)
            ->assertViewHas('availability.0.data.price', '50.00')
            ->assertViewHas('availability.+1.data.price', '50.00')
            ->assertViewHas('availability.+1.request.date_start', $this->date->copy()->addDays(2)->format('Y-m-d'));
    }

    /** @test */
    public function returns_availability_for_specific_advanced()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'test',
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '100.00')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 2)
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'is-this-real-life-or-just-testing',
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function creates_reservation_when_checkout_is_called()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'test',
                ]
            );

        $availability = $component->viewData('availability');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations',
            [
                'item_id' => $this->advancedEntries->first()->id(),
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(2, 'day'),
                'quantity' => 1,
                'property' => 'test',
                'payment' => data_get($availability, 'data.payment'),
            ]
        );

        Config::set('resrv-config.minutes_to_hold', 10);
        // Check that availability gets decreased here
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->advancedEntries->first()->id(),
            'date' => $this->date->startOfDay(),
            'available' => 0,
        ]);

        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();

        // Call availability to run the jobs
        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'test',
                ]
            );

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $this->advancedEntries->first()->id(),
            'date' => $this->date->startOfDay(),
            'available' => 1,
        ]);
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $this->advancedEntries->first()->id(),
            'date_start' => $this->date->setTime(12, 0, 0),
            'status' => 'expired',
        ]);

    }

    /** @test */
    public function redirects_to_checkout_after_reservation_is_created()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'test',
                ]
            )
            ->call('checkout')
            ->assertSessionHas('resrv_reservation', 1)
            ->assertRedirect($entry->url());
    }
}

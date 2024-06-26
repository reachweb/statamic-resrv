<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class AvailabilityResultsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    public $option;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));

        $this->option = Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->create([
                'item_id' => $this->entries->first()->id(),
            ]);

        $extra = Extra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);
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
    public function returns_availability_for_all_advanced_sorted_by_price()
    {
        $availabilityData = [
            'available' => 1,
            'statamic_id' => $this->advancedEntries->first()->id(),
        ];

        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')],
                ['date' => today()->add(2, 'day')],
                ['date' => today()->add(3, 'day')],
            )
            ->create([
                ...$availabilityData,
                'price' => 25,
                'property' => 'test2',
            ]);

        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')],
                ['date' => today()->add(2, 'day')],
                ['date' => today()->add(3, 'day')],
            )
            ->create([...$availabilityData,
                'price' => 75,
                'property' => 'test3',
            ]);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'any',
                ]
            )
            ->assertViewHas('availability.data.test')
            ->assertViewHas('availability.data.test.price', '100.00')
            ->assertViewHas('availability.data.test2.price', '50.00')
            ->assertViewHas('availability.data.test3.price', '150.00')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 2);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(5, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'any',
                ]
            )
            ->assertViewMissing('availability.data.test')
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function gets_options_if_property_is_enabled()
    {
        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'showOptions' => true])
            ->assertSet('showOptions', true)
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
            ->assertSee('Reservation option');

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
    }

    /** @test */
    public function gets_extras_if_property_is_enabled()
    {
        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'showExtras' => true])
            ->assertSet('showExtras', true)
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
            ->assertSee('This is an extra');

        $this->assertEquals('This is an extra', $component->extras->first()->name);
        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    /** @test */
    public function applies_dynamic_pricing_if_conditions_are_met()
    {
        $dynamic = DynamicPricing::factory()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

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
            ->assertViewHas('availability.data.original_price', null)
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

    /** @test */
    public function applies_dynamic_pricing_if_conditions_are_met_price_over()
    {
        $dynamic = DynamicPricing::factory()->conditionPriceOver()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
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
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '50.00')
            ->assertViewHas('availability.data.original_price', null)
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

    /** @test */
    public function applies_dynamic_pricing_if_conditions_are_met_fixed_increase()
    {
        $dynamic = DynamicPricing::factory()->fixedIncrease()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '160.08')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

    /** @test */
    public function applies_dynamic_pricing_coupon_when_in_session()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_coupon' => '20OFF']);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
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
                'price' => data_get($availability, 'data.price'),
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
    public function creates_reservation_and_saves_custom_data_in_the_database()
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
                    'customer' => [
                        'adults' => 2,
                        'children' => 1,
                    ],
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
                'price' => data_get($availability, 'data.price'),
                'customer' => json_encode(['adults' => 2, 'children' => 1]),
            ]
        );
    }

    /** @test */
    public function does_not_create_reservation_if_availability_changed()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
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
            ->assertViewHas('availability.data.price', '100.00');

        // Change the availability manually
        Availability::where('statamic_id', $this->entries->first()->id())
            ->where('date', $this->date->startOfDay())
            ->update(['available' => 0]);

        // Call checkout and get a validation error
        $component->call('checkout')
            ->assertHasErrors('availability');
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

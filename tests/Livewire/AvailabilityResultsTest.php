<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
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

    protected function setUp(): void
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

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($extra->id);
    }

    // Test that the component renders successfully
    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-results')
            ->assertStatus(200);
    }

    // Test that it can set the extra days parameter
    public function test_can_set_extra_days_parameter()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->assertSet('extraDays', 2)
            ->assertStatus(200);
    }

    // Test that it can access the Statamic entry
    public function test_can_access_the_statamic_entry()
    {
        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertStatus(200);

        $this->assertEquals($this->entries->first(), $component->entry);
    }

    // Test that it listens to the availability-search-updated event and shows price and availability or not
    public function test_listens_to_the_availability_search_updated_event()
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

    // Test that it returns no availability (false) if no availability is set
    public function test_returns_no_availability_if_zero()
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

    // Test that it returns no availability (false) if the item is set to stop sales (resrv_availability = 'disabled')
    public function test_returns_no_availability_if_stop_sales()
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

    // Test that it returns no availability (false) if the quantity is not enough
    public function test_returns_no_availability_if_quantity_not_enough()
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

    // Test that it returns no availability (false) if the closer than minimum_days_before allows
    public function test_returns_no_availability_if_date_is_too_close()
    {
        Config::set('resrv-config.minimum_days_before', 2);

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
            ->assertHasErrors('availability')
            ->assertSee('The starting date is closer than allowed');
    }

    // Test that it returns the correct price for extra quantity
    public function test_returns_correct_price_for_extra_quantity()
    {
        Availability::where('statamic_id', $this->entries->first()->id())
            ->update(['available' => 2]);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 2,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '200.00');
    }

    // Test that it returns false if one day is zero availability
    public function test_returns_false_if_one_day_is_zero_availability()
    {
        $zeroAvailabilityEntry = $this->makeStatamicItemWithAvailability(customAvailability: [
            'available' => [1, 1, 0, 1],
        ]);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $zeroAvailabilityEntry->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    // Test that it returns the correct prices if the prices vary
    public function test_availability_variable_prices()
    {
        $varyingPriceEntry = $this->makeStatamicItemWithAvailability(customAvailability: [
            'price' => [50, 30, 50, 50],
        ]);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $varyingPriceEntry->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '130.00');
    }

    // Test that it gives an error for reservations less than the minimum days setting
    public function test_availability_honors_min_days_setting()
    {
        Config::set('resrv-config.minimum_reservation_period_in_days', 3);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries['normal']->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertHasErrors('availability');
    }

    // Test that it gives an error for reservations more than the maximum days setting
    public function test_availability_honors_max_days_setting()
    {
        Config::set('resrv-config.maximum_reservation_period_in_days', 3);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(5, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries['normal']->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertHasErrors('availability');
    }

    // Test that it calculates correct prices for decimals
    public function test_availability_prices()
    {
        $this->travelTo(today()->setHour(11));

        $item = $this->makeStatamicItemWithAvailability(customAvailability: [
            'dates' => [
                today(),
                today()->addDay(),
                today()->addDays(2),
                today()->addDays(3),
            ],
            'price' => 25.23,
            'available' => 2,
        ]);

        $searchPayload = [
            'dates' => [
                'date_start' => today()->setHour(12)->toISOString(),
                'date_end' => today()->setHour(12)->add(3, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $item->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '75.69')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 3);
    }

    // Test that it ignores quantity for pricing if configured
    public function test_returns_same_price_for_extra_quantity_if_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        Availability::where('statamic_id', $this->entries->first()->id())
            ->update(['available' => 2]);

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 2,
                    'advanced' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '100.00');
    }

    // Test that it returns availability for any extra requested days
    public function test_returns_availability_for_extra_requested_days()
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

    // Test that it returns availability for any extra requested days with offset
    public function test_returns_availability_for_extra_requested_days_with_offset()
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

    // Test that it returns availability for specific advanced property
    public function test_returns_availability_for_specific_advanced()
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

    // Test that it returns availability for all advanced properties sorted by price if searching for advanced 'any'
    public function test_returns_availability_for_all_advanced_sorted_by_price()
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

    // Test that it returns availability for all advanced properties when advanced is true
    public function test_returns_availability_for_all_properties_when_advanced_is_true()
    {
        // Add a second property to this entry

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
            )
            ->create([
                'available' => 1,
                'price' => 50,
                'statamic_id' => $this->advancedEntries->first()->id(),
                'property' => 'another-test']);

        // Test for one day
        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id(), 'advanced' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'any', // This will be overridden by the component
                ]
            )
            ->assertSet('advanced', true)
            ->assertSeeHtml('Test Property')
            ->assertViewHas('availability.test')
            ->assertViewHas('availability.another-test')
            ->assertViewHas('availability.test.data.price')
            ->assertViewHas('availability.another-test.data.price');

        // Test for two days
        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id(), 'advanced' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'any', // This will be overridden by the component
                ]
            )
            ->assertSet('advanced', true)
            ->assertSeeHtml('Test Property')
            ->assertViewHas('availability.test')
            ->assertViewHas('availability.another-test')
            ->assertViewHas('availability.test.data.price')
            ->assertViewMissing('availability.another-test.data.price')
            ->assertViewHas('availability.another-test.message.status', false);
    }

    // Test that checkout method works correctly when advanced is true and a property has been selected via checkoutProperty
    public function test_checkout_after_checkout_property_when_advanced_is_true()
    {
        $checkoutPage = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);
        $checkoutPage->save();
        Config::set('resrv-config.checkout_entry', $checkoutPage->id());

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id(), 'advanced' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => 'any',
                ]
            );

        $availability = $component->viewData('availability');
        $propertyToCheckout = 'test';

        // Checkout
        $component->call('checkoutProperty', $propertyToCheckout)->assertRedirect($checkoutPage->url());

        // The reservation should be for the property selected via checkoutProperty
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $this->advancedEntries->first()->id(),
            'property' => $propertyToCheckout,
            'price' => data_get($availability, $propertyToCheckout.'.data.price'),
        ]);

        // Ensure data.advanced was updated to the specific property
        $this->assertEquals($propertyToCheckout, $component->get('data.advanced'));
    }

    // Test that it gets options if property is enabled
    public function test_gets_options_if_property_is_enabled()
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
            ->assertSee('Reservation option')
            ->assertSee('45.50');
    }

    // Test that it gets extras if property is enabled
    public function test_gets_extras_if_property_is_enabled()
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
            ->assertSee('This is an extra')
            ->assertSee('9.30');
    }

    // Test that it applies dynamic pricing if conditions are met
    public function test_applies_dynamic_pricing_if_conditions_are_met()
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

    // Test that it applies dynamic pricing if conditions are met price_over
    public function test_applies_dynamic_pricing_for_reservation_price_over_an_amount()
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

    // Test that it applies dynamic pricing with a fixed increase
    public function test_applies_dynamic_pricing_if_conditions_are_met_fixed_increase()
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

    // Test that it applies dynamic pricing with a coupon when it is set in the session
    public function test_applies_dynamic_pricing_coupon_when_in_session()
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

    // Test that it creates a reservation when the checkout method is called and that it decreases availability
    public function test_creates_reservation_when_checkout_is_called()
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

    // Test that it creates a reservation and saves customer data in the database if present before checkout
    public function test_creates_reservation_and_saves_custom_data_in_the_database()
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
                'customer_id' => 1,
            ]
        );

        $this->assertDatabaseHas('resrv_customers',
            [
                'data' => json_encode(['adults' => 2, 'children' => 1]),
            ]
        );
    }

    // Test that it creates a reservation when multiple days results are enabled
    public function test_checkout_works_for_multiple_days()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id(), 'extraDays' => 1, 'extraDaysOffset' => 1])
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
                'payment' => data_get($availability[0], 'data.payment'),
                'price' => data_get($availability[0], 'data.price'),
                'customer_id' => 1,
            ]
        );

        $this->assertDatabaseHas('resrv_customers',
            [
                'data' => json_encode(['adults' => 2, 'children' => 1]),
            ]
        );
    }

    // Test that it return an error if the availability has changed after the results have been shown
    public function test_does_not_create_reservation_if_availability_changed()
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

    // Test that it starts the checkout correctly when extra quantity is ignored for pricing
    public function test_starts_checkout_correctly_when_extra_quantity_is_ignored_for_pricing()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        Availability::where('statamic_id', $this->advancedEntries->first()->id())
            ->update(['available' => 3]);

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 3,
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
                'quantity' => 3,
                'property' => 'test',
                'payment' => data_get($availability, 'data.payment'),
                'price' => data_get($availability, 'data.price'),
            ]
        );
    }

    // Test that it redirects to the checkout after a reservation is created
    public function test_redirects_to_checkout_after_reservation_is_created()
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

    // Test that it creates a reservation and saves affiliate when the cookie is in the session
    public function test_creates_reservation_and_saves_affiliate_when_cookie_is_in_session()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        $affiliate = Affiliate::factory()->create();

        Config::set('resrv-config.checkout_entry', $entry->id());

        $component = Livewire::withCookies(['resrv_afid' => $affiliate->code])
            ->test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
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

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservation_affiliate',
            [
                'reservation_id' => 1,
                'affiliate_id' => $affiliate->id,
                'fee' => $affiliate->fee,
            ]
        );
    }

    public function test_cutoff_ignores_when_disabled_per_entry()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Create an entry with cutoff rules disabled
        $entry = $this->makeStatamicItemWithAvailability();
        $resrvEntry = \Reach\StatamicResrv\Models\Entry::whereItemId($entry->id());

        // Set options but with cutoff disabled
        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => false,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $resrvEntry->save();

        // Try to make a reservation for today (which would be within cutoff if enabled)
        $today = now()->startOfDay();

        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->toISOString(),
                    'date_end' => $today->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasNoErrors()
            ->assertDispatched('availability-results-updated');
    }

    public function test_cutoff_enforces_default_setting()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Create an entry with cutoff rules enabled (3 hours before 4pm)
        $entry = $this->makeStatamicItemWithAvailability();
        $resrvEntry = \Reach\StatamicResrv\Models\Entry::whereItemId($entry->id());

        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $resrvEntry->save();

        // Mock current time to be 2pm (within cutoff window for today's 4pm start)
        $this->travelTo(now()->setTime(14, 0, 0));
        $today = now()->startOfDay();

        // Try to make a reservation for today (should fail due to cutoff)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->toISOString(),
                    'date_end' => $today->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');

        // Try to make a reservation for tomorrow (should work)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->copy()->add(1, 'day')->toISOString(),
                    'date_end' => $today->copy()->add(2, 'days')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasNoErrors()
            ->assertDispatched('availability-results-updated');
    }

    public function test_cutoff_enforces_correct_schedule_based_on_date()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Create an entry with cutoff rules and schedules
        $entry = $this->makeStatamicItemWithAvailability();
        $resrvEntry = \Reach\StatamicResrv\Models\Entry::whereItemId($entry->id());

        // Set up realistic 2-month schedules
        $currentMonthStart = now()->startOfMonth()->format('Y-m-d');
        $currentMonthEnd = now()->endOfMonth()->format('Y-m-d');
        $nextMonthStart = now()->addMonth()->startOfMonth()->format('Y-m-d');
        $nextMonthEnd = now()->addMonth()->endOfMonth()->format('Y-m-d');

        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
                'schedules' => [
                    [
                        'date_start' => $currentMonthStart,
                        'date_end' => $currentMonthEnd,
                        'starting_time' => '10:00',
                        'cutoff_hours' => 6,
                        'name' => 'Current Month Schedule',
                    ],
                    [
                        'date_start' => $nextMonthStart,
                        'date_end' => $nextMonthEnd,
                        'starting_time' => '10:00',
                        'cutoff_hours' => 4,
                        'name' => 'Next Month Schedule',
                    ],
                ],
            ],
        ];
        $resrvEntry->save();

        // Test current month schedule (6 hour cutoff)
        // Mock current time to be 8am (within 6-hour cutoff window for 10am start)
        $this->travelTo(now()->setTime(8, 0, 0));

        // Try to make a reservation for today in current month (should fail due to 6-hour cutoff)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => now()->startOfDay()->toISOString(),
                    'date_end' => now()->startOfDay()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');

        // Try to make a reservation for tomorrow in next month (should just work)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => now()->addMonth()->toISOString(),
                    'date_end' => now()->addMonth()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasNoErrors()
            ->assertDispatched('availability-results-updated');

        // Test next month schedule cutoff enforcement
        // Mock current time to be 7am (within 4-hour cutoff window for 10am start)
        $this->travelTo(now()->addMonth()->setTime(7, 0, 0));

        // Try to make a reservation for tomorrow in next month (should fail due to 4-hour cutoff)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => now()->toISOString(),
                    'date_end' => now()->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');

        // Test date outside both schedules (should use default)
        // Mock current time to be 2pm (within 3-hour cutoff for default 4pm start)
        $this->travelTo(now()->addMonths(2)->setTime(14, 0, 0));

        // Try to make a reservation outside schedule dates (should fail due to default 3-hour cutoff)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => now()->toISOString(),
                    'date_end' => now()->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');
    }

    public function test_cutoff_allows_reservation_when_time_has_passed_today()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Create an entry with cutoff rules enabled (3 hours before 4pm)
        $entry = $this->makeStatamicItemWithAvailability();
        $resrvEntry = \Reach\StatamicResrv\Models\Entry::whereItemId($entry->id());

        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $resrvEntry->save();

        // Mock current time to be 6pm (after today's 4pm start time has passed)
        $this->travelTo(now()->setTime(18, 0, 0));
        $today = now()->startOfDay();

        // Try to make a reservation for today (should not work - time has already passed)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->toISOString(),
                    'date_end' => $today->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');

        // Test with schedule - current time 12pm (after today's 10am start time has passed)
        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
                'schedules' => [
                    [
                        'date_start' => $today->format('Y-m-d'),
                        'date_end' => $today->format('Y-m-d'),
                        'starting_time' => '10:00',
                        'cutoff_hours' => 6,
                        'name' => 'Morning Schedule',
                    ],
                ],
            ],
        ];
        $resrvEntry->save();

        $this->travelTo(now()->setTime(12, 0, 0));

        // Try to make a reservation for today (should not work - 10am start time has passed)
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->toISOString(),
                    'date_end' => $today->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertHasErrors(['cutoff'])
            ->assertDispatched('availability-results-updated');
    }

    public function test_cutoff_enforces_for_extra_requested_days()
    {
        // Enable cutoff rules globally
        Config::set('resrv-config.enable_cutoff_rules', true);

        // Create an entry with cutoff rules enabled (3 hours before 4pm)
        $entry = $this->makeStatamicItemWithAvailability();
        $resrvEntry = \Reach\StatamicResrv\Models\Entry::whereItemId($entry->id());

        $resrvEntry->options = [
            'cutoff_rules' => [
                'enable_cutoff' => true,
                'default_starting_time' => '16:00',
                'default_cutoff_hours' => 3,
            ],
        ];
        $resrvEntry->save();

        // Mock current time to be 2pm (within cutoff window for today's 4pm start)
        $this->travelTo(now()->setTime(14, 0, 0));
        $today = now()->startOfDay();

        // Test with extraDays = 2 (should show availability for -2, -1, 0, +1, +2)
        // Today (0) should fail due to cutoff, but others should work
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id(), 'extraDays' => 2])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $today->toISOString(),
                    'date_end' => $today->copy()->add(2, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->ray()
            ->assertViewHasAll([
                'availability.-2',
                'availability.-1',
                'availability.0',
                'availability.+1',
                'availability.+2',
            ])
            // Days -2 and -1 should be false (in the past)
            ->assertViewHas('availability.-2.message.status', false)
            ->assertViewHas('availability.-1.message.status', false)
            // Today (0) should fail due to cutoff
            ->assertViewHas('availability.0.message.status', false)
            // Future days (+1, +2) should have availability
            ->assertViewHas('availability.+1.data.price', '100.00')
            ->assertViewHas('availability.+2.data.price', '100.00')
            ->assertViewHas('availability.+1.request.date_start', $today->copy()->addDays(1)->format('Y-m-d'))
            ->assertViewHas('availability.+2.request.date_start', $today->copy()->addDays(2)->format('Y-m-d'));

        // Test when current time is after cutoff (10am the next day)
        $this->travelTo(now()->addDay()->setTime(10, 0, 0));

        // Now today's slot should work since we're well before the cutoff
        Livewire::test(AvailabilityResults::class, ['entry' => $entry->id(), 'extraDays' => 1])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => now()->startOfDay()->toISOString(),
                    'date_end' => now()->startOfDay()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertViewHasAll([
                'availability.-1',
                'availability.0',
                'availability.+1',
            ])
            // Yesterday should be false (in the past)
            ->assertViewHas('availability.-1.message.status', false)
            // Today should work (well before cutoff)
            ->assertViewHas('availability.0.data.price', '50.00')
            // Tomorrow should work
            ->assertViewHas('availability.+1.data.price', '50.00');
    }
}

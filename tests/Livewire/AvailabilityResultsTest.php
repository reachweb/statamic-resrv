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
use Reach\StatamicResrv\Models\Rate;
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

        ResrvEntry::whereItemId($this->entries->first()->id)
            ->extras()
            ->attach($extra->id);
    }

    protected function createCheckoutEntry(): Entry
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        return $entry;
    }

    protected function getFirstAdvancedEntryRateId(): int
    {
        return Rate::forEntry($this->advancedEntries->first()->id())->first()->id;
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-results')
            ->assertStatus(200);
    }

    public function test_can_set_extra_days_parameter()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->assertSet('extraDays', 2)
            ->assertStatus(200);
    }

    public function test_can_access_the_statamic_entry()
    {
        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertStatus(200);

        $this->assertEquals($this->entries->first(), $component->entry);
    }

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
                    'rate' => null,
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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

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
                    'rate' => null,
                ]
            )
            ->assertHasErrors('availability')
            ->assertSee('The starting date is closer than allowed');
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '200.00');
    }

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
            'rate' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $zeroAvailabilityEntry->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

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
            'rate' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $varyingPriceEntry->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '130.00');
    }

    public function test_availability_honors_min_days_setting()
    {
        Config::set('resrv-config.minimum_reservation_period_in_days', 3);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'rate' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries['normal']->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertHasErrors('availability');
    }

    public function test_availability_honors_max_days_setting()
    {
        Config::set('resrv-config.maximum_reservation_period_in_days', 3);

        $searchPayload = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(5, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'rate' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries['normal']->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertHasErrors('availability');
    }

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
            'rate' => null,
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $item->id()])
            ->dispatch('availability-search-updated', $searchPayload)
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '75.69')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 3);
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '100.00');
    }

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
                    'rate' => null,
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
                    'rate' => null,
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

    public function test_returns_availability_for_specific_advanced()
    {
        $rateId = $this->getFirstAdvancedEntryRateId();

        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
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
                    'rate' => '99999',
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    public function test_returns_availability_for_all_advanced_sorted_by_price()
    {
        $entryId = $this->advancedEntries->first()->id();

        $rate2 = Rate::factory()->create([
            'collection' => 'advanced',
            'slug' => 'test2',
            'title' => 'Test2',
        ]);

        $rate3 = Rate::factory()->create([
            'collection' => 'advanced',
            'slug' => 'test3',
            'title' => 'Test3',
        ]);

        $availabilityData = [
            'available' => 1,
            'statamic_id' => $entryId,
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
                'rate_id' => $rate2->id,
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
                'rate_id' => $rate3->id,
            ]);

        $testRate = Rate::forEntry($entryId)->where('slug', 'test')->first();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            );

        $availability = $component->viewData('availability');
        $data = collect($availability['data']);

        // Results are sorted by price and contain rate_id for identification
        $this->assertCount(3, $data);
        $this->assertEquals('50.00', $data->firstWhere('rate_id', $rate2->id)['price']);
        $this->assertEquals('100.00', $data->firstWhere('rate_id', $testRate->id)['price']);
        $this->assertEquals('150.00', $data->firstWhere('rate_id', $rate3->id)['price']);

        $component->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 2);

        Livewire::test(AvailabilityResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(5, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    public function test_returns_availability_for_all_rates_when_advanced_is_true()
    {
        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::forEntry($entryId)->first();

        $anotherRate = Rate::factory()->create([
            'collection' => 'advanced',
            'slug' => 'another-test-extra',
            'title' => 'Another Test Extra',
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
            )
            ->create([
                'available' => 1,
                'price' => 50,
                'statamic_id' => $entryId,
                'rate_id' => $anotherRate->id,
            ]);

        Livewire::test(AvailabilityResults::class, ['entry' => $entryId, 'rates' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any', // This will be overridden by the component
                ]
            )
            ->assertSet('rates', true)
            ->assertSeeHtml('Test')
            ->assertViewHas('availability.'.$testRate->id)
            ->assertViewHas('availability.'.$anotherRate->id)
            ->assertViewHas('availability.'.$testRate->id.'.data.price')
            ->assertViewHas('availability.'.$anotherRate->id.'.data.price');

        Livewire::test(AvailabilityResults::class, ['entry' => $entryId, 'rates' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any', // This will be overridden by the component
                ]
            )
            ->assertSet('rates', true)
            ->assertSeeHtml('Test')
            ->assertViewHas('availability.'.$testRate->id)
            ->assertViewHas('availability.'.$anotherRate->id)
            ->assertViewHas('availability.'.$testRate->id.'.data.price')
            ->assertViewMissing('availability.'.$anotherRate->id.'.data.price')
            ->assertViewHas('availability.'.$anotherRate->id.'.message.status', false);
    }

    public function test_checkout_after_checkout_rate_when_advanced_is_true()
    {
        $checkoutPage = $this->createCheckoutEntry();

        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::forEntry($entryId)->first();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $entryId, 'rates' => true])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            );

        $availability = $component->viewData('availability');
        $rateToCheckout = $testRate->id;

        // Checkout
        $component->call('checkoutRate', (string) $rateToCheckout)->assertRedirect($checkoutPage->url());

        // The reservation should be for the rate selected via checkoutRate
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'rate_id' => $rateToCheckout,
            'price' => data_get($availability, $rateToCheckout.'.data.price'),
        ]);

        // Ensure data.rate was updated to the specific rate
        $this->assertEquals((string) $rateToCheckout, $component->get('data.rate'));
    }

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
                    'rate' => null,
                ]
            )
            ->assertSee('Reservation option')
            ->assertSee('45.50');
    }

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
                    'rate' => null,
                ]
            )
            ->assertSee('This is an extra')
            ->assertSee('9.30');
    }

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
                    'rate' => null,
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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

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
                    'rate' => null,
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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '160.08')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

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
                    'rate' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '120.00')
            ->assertViewHas('availability.data.original_price', '150.00');
    }

    public function test_creates_reservation_when_checkout_is_called()
    {
        $this->createCheckoutEntry();

        $rateId = $this->getFirstAdvancedEntryRateId();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
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
                'rate_id' => $rateId,
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
                    'rate' => (string) $rateId,
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

    public function test_creates_reservation_and_saves_custom_data_in_the_database()
    {
        $this->createCheckoutEntry();

        $rateId = $this->getFirstAdvancedEntryRateId();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
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
                'rate_id' => $rateId,
                'payment' => data_get($availability, 'data.payment'),
                'price' => data_get($availability, 'data.price'),
                'customer_id' => 1,
            ]
        );

        $this->assertDatabaseHasJsonColumn('resrv_customers', [], 'data', ['adults' => 2, 'children' => 1]);
    }

    public function test_checkout_works_for_multiple_days()
    {
        $this->createCheckoutEntry();

        $rateId = $this->getFirstAdvancedEntryRateId();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id(), 'extraDays' => 1, 'extraDaysOffset' => 1])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
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
                'rate_id' => $rateId,
                'payment' => data_get($availability[0], 'data.payment'),
                'price' => data_get($availability[0], 'data.price'),
                'customer_id' => 1,
            ]
        );

        $this->assertDatabaseHasJsonColumn('resrv_customers', [], 'data', ['adults' => 2, 'children' => 1]);
    }

    public function test_does_not_create_reservation_if_availability_changed()
    {
        $this->createCheckoutEntry();

        $component = Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => null,
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

    public function test_starts_checkout_correctly_when_extra_quantity_is_ignored_for_pricing()
    {
        $this->createCheckoutEntry();
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $rateId = $this->getFirstAdvancedEntryRateId();

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
                    'rate' => (string) $rateId,
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
                'rate_id' => $rateId,
                'payment' => data_get($availability, 'data.payment'),
                'price' => data_get($availability, 'data.price'),
            ]
        );
    }

    public function test_redirects_to_checkout_after_reservation_is_created()
    {
        $checkoutEntry = $this->createCheckoutEntry();

        $rateId = $this->getFirstAdvancedEntryRateId();

        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
                ]
            )
            ->call('checkout')
            ->assertSessionHas('resrv_reservation', 1)
            ->assertRedirect($checkoutEntry->url());
    }

    public function test_creates_reservation_and_saves_affiliate_when_cookie_is_in_session()
    {
        $this->createCheckoutEntry();

        $rateId = $this->getFirstAdvancedEntryRateId();

        $affiliate = Affiliate::factory()->create();

        $component = Livewire::withCookies(['resrv_afid' => $affiliate->code])
            ->test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $rateId,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
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
                'rate' => null,
            ])
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
                'rate' => null,
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

<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityList;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityListTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityList::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-list')
            ->assertStatus(200);
    }

    public function test_can_access_the_statamic_entry()
    {
        $component = Livewire::test(AvailabilityList::class, ['entry' => $this->entries->first()->id()])
            ->assertStatus(200);

        $this->assertEquals($this->entries->first(), $component->entry);
    }

    public function test_returns_available_dates_from_start_date()
    {
        Livewire::test(AvailabilityList::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => null,
                ]
            )
            ->assertViewHas('availableDates')
            ->assertViewHas('availableDates.none');
    }

    public function test_dispatches_availability_results_updated_event()
    {
        Livewire::test(AvailabilityList::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => null,
                ]
            )
            ->assertDispatched('availability-results-updated');
    }

    public function test_available_dates_returns_correct_prices()
    {
        $entry = $this->makeStatamicItemWithAvailability(customAvailability: [
            'dates' => [today(), today()->addDay(), today()->addDays(2), today()->addDays(3)],
            'price' => [25, 50, 75, 100],
            'available' => 2,
        ]);

        $component = Livewire::test(AvailabilityList::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => null,
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey('none', $availableDates->toArray());
        $dates = $availableDates['none'];

        $this->assertEquals('25.00', $dates[today()->format('Y-m-d')]['price']);
        $this->assertEquals('50.00', $dates[today()->addDay()->format('Y-m-d')]['price']);
        $this->assertEquals('75.00', $dates[today()->addDays(2)->format('Y-m-d')]['price']);
        $this->assertEquals('100.00', $dates[today()->addDays(3)->format('Y-m-d')]['price']);
    }

    public function test_available_dates_respects_quantity_filter()
    {
        $entry = $this->makeStatamicItemWithAvailability(customAvailability: [
            'dates' => [today(), today()->addDay(), today()->addDays(2), today()->addDays(3)],
            'price' => 50,
            'available' => [1, 2, 1, 2],
        ]);

        $component = Livewire::test(AvailabilityList::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 2,
                    'rate' => null,
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey('none', $availableDates->toArray());
        $dates = $availableDates['none'];

        $this->assertCount(2, $dates);
        $this->assertArrayHasKey(today()->addDay()->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDays(3)->format('Y-m-d'), $dates);
    }

    public function test_available_dates_returns_grouped_by_property_for_advanced()
    {
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'available' => 1,
                'price' => 75,
                'statamic_id' => $this->advancedEntries->first()->id(),
                'property' => 'another-test',
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $this->advancedEntries->first()->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey('test', $availableDates->toArray());
        $this->assertArrayHasKey('another-test', $availableDates->toArray());

        $testDates = $availableDates['test'];
        $anotherTestDates = $availableDates['another-test'];

        $this->assertCount(4, $testDates);
        $this->assertCount(4, $anotherTestDates);

        $this->assertEquals('50.00', $testDates[today()->format('Y-m-d')]['price']);
        $this->assertEquals('75.00', $anotherTestDates[today()->format('Y-m-d')]['price']);
    }

    public function test_available_dates_returns_only_selected_property()
    {
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'available' => 1,
                'price' => 75,
                'statamic_id' => $this->advancedEntries->first()->id(),
                'property' => 'another-test',
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $this->advancedEntries->first()->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'test',
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey('test', $availableDates->toArray());
        $this->assertArrayNotHasKey('another-test', $availableDates->toArray());

        $testDates = $availableDates['test'];
        $this->assertCount(4, $testDates);
        $this->assertEquals('50.00', $testDates[today()->format('Y-m-d')]['price']);
    }

    public function test_group_by_date_returns_date_first_structure()
    {
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'available' => 1,
                'price' => 75,
                'statamic_id' => $this->advancedEntries->first()->id(),
                'property' => 'another-test',
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $this->advancedEntries->first()->id(),
            'groupByDate' => true,
            'rates' => true,
        ])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $todayKey = today()->format('Y-m-d');
        $this->assertArrayHasKey($todayKey, $availableDates->toArray());

        $todayData = $availableDates[$todayKey];
        $this->assertArrayHasKey('test', $todayData);
        $this->assertArrayHasKey('another-test', $todayData);

        $this->assertEquals('50.00', $todayData['test']['price']);
        $this->assertEquals('75.00', $todayData['another-test']['price']);

        $this->assertEquals(1, $todayData['test']['available']);
        $this->assertEquals(1, $todayData['another-test']['available']);
    }

    public function test_group_by_date_shows_sparse_availability()
    {
        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'available' => 1,
                'price' => 100,
                'statamic_id' => $this->advancedEntries->first()->id(),
                'property' => 'another-test',
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $this->advancedEntries->first()->id(),
            'groupByDate' => true,
            'rates' => true,
        ])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => 'any',
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $todayKey = today()->format('Y-m-d');
        $day1Key = today()->addDay()->format('Y-m-d');
        $day2Key = today()->addDays(2)->format('Y-m-d');

        $this->assertArrayHasKey('test', $availableDates[$todayKey]);
        $this->assertArrayHasKey('another-test', $availableDates[$todayKey]);

        $this->assertArrayHasKey('test', $availableDates[$day1Key]);
        $this->assertArrayNotHasKey('another-test', $availableDates[$day1Key]);

        $this->assertArrayHasKey('test', $availableDates[$day2Key]);
        $this->assertArrayHasKey('another-test', $availableDates[$day2Key]);
    }

    public function test_dispatches_event_on_date_selection()
    {
        Livewire::test(AvailabilityList::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => null,
                ]
            )
            ->call('selectDate', '2025-12-05', 'none')
            ->assertDispatched('availability-date-selected', [
                'date' => '2025-12-05',
                'rate_id' => 'none',
            ]);
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityList;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
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
        $entryId = $this->entries->first()->id();
        $rateId = Rate::where('statamic_id', $entryId)->first()->id;

        $component = Livewire::test(AvailabilityList::class, ['entry' => $entryId])
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
            ->assertViewHas('availableDates');

        $availableDates = $component->viewData('availableDates');
        $this->assertArrayHasKey($rateId, $availableDates->toArray());
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
        $rateId = Rate::where('statamic_id', $entry->id())->first()->id;

        $this->assertArrayHasKey($rateId, $availableDates->toArray());
        $dates = $availableDates[$rateId];

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
        $rateId = Rate::where('statamic_id', $entry->id())->first()->id;

        $this->assertArrayHasKey($rateId, $availableDates->toArray());
        $dates = $availableDates[$rateId];

        $this->assertCount(2, $dates);
        $this->assertArrayHasKey(today()->addDay()->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDays(3)->format('Y-m-d'), $dates);
    }

    public function test_available_dates_returns_grouped_by_rate_id_for_advanced()
    {
        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::where('statamic_id', $entryId)->first();

        $anotherRate = Rate::factory()->create([
            'statamic_id' => $entryId,
            'slug' => 'another-test',
            'title' => 'Another Test',
        ]);

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
                'statamic_id' => $entryId,
                'rate_id' => $anotherRate->id,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entryId,
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

        $this->assertArrayHasKey($testRate->id, $availableDates->toArray());
        $this->assertArrayHasKey($anotherRate->id, $availableDates->toArray());

        $testDates = $availableDates[$testRate->id];
        $anotherTestDates = $availableDates[$anotherRate->id];

        $this->assertCount(4, $testDates);
        $this->assertCount(4, $anotherTestDates);

        $this->assertEquals('50.00', $testDates[today()->format('Y-m-d')]['price']);
        $this->assertEquals('75.00', $anotherTestDates[today()->format('Y-m-d')]['price']);
    }

    public function test_available_dates_returns_only_selected_rate()
    {
        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::where('statamic_id', $entryId)->first();

        $anotherRate = Rate::factory()->create([
            'statamic_id' => $entryId,
            'slug' => 'another-test',
            'title' => 'Another Test',
        ]);

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
                'statamic_id' => $entryId,
                'rate_id' => $anotherRate->id,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entryId,
            'rates' => true,
        ])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => today()->setHour(12)->toISOString(),
                        'date_end' => today()->setHour(12)->add(30, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'rate' => (string) $testRate->id,
                ]
            );

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($testRate->id, $availableDates->toArray());
        $this->assertArrayNotHasKey($anotherRate->id, $availableDates->toArray());

        $testDates = $availableDates[$testRate->id];
        $this->assertCount(4, $testDates);
        $this->assertEquals('50.00', $testDates[today()->format('Y-m-d')]['price']);
    }

    public function test_group_by_date_returns_date_first_structure()
    {
        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::where('statamic_id', $entryId)->first();

        $anotherRate = Rate::factory()->create([
            'statamic_id' => $entryId,
            'slug' => 'another-test',
            'title' => 'Another Test',
        ]);

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
                'statamic_id' => $entryId,
                'rate_id' => $anotherRate->id,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entryId,
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
        $this->assertArrayHasKey($testRate->id, $todayData);
        $this->assertArrayHasKey($anotherRate->id, $todayData);

        $this->assertEquals('50.00', $todayData[$testRate->id]['price']);
        $this->assertEquals('75.00', $todayData[$anotherRate->id]['price']);

        $this->assertEquals(1, $todayData[$testRate->id]['available']);
        $this->assertEquals(1, $todayData[$anotherRate->id]['available']);
    }

    public function test_group_by_date_shows_sparse_availability()
    {
        $entryId = $this->advancedEntries->first()->id();
        $testRate = Rate::where('statamic_id', $entryId)->first();

        $anotherRate = Rate::factory()->create([
            'statamic_id' => $entryId,
            'slug' => 'another-test',
            'title' => 'Another Test',
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'available' => 1,
                'price' => 100,
                'statamic_id' => $entryId,
                'rate_id' => $anotherRate->id,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entryId,
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

        $this->assertArrayHasKey($testRate->id, $availableDates[$todayKey]);
        $this->assertArrayHasKey($anotherRate->id, $availableDates[$todayKey]);

        $this->assertArrayHasKey($testRate->id, $availableDates[$day1Key]);
        $this->assertArrayNotHasKey($anotherRate->id, $availableDates[$day1Key]);

        $this->assertArrayHasKey($testRate->id, $availableDates[$day2Key]);
        $this->assertArrayHasKey($anotherRate->id, $availableDates[$day2Key]);
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

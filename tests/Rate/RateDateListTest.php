<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityList;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class RateDateListTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
        $this->travelTo(today()->setHour(12));
    }

    public function test_date_list_applies_relative_rate_pricing()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard',
        ]);

        $relativeRate = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 20,
        ]);

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $relativeRate->id,
                'price' => 100,
                'available' => 2,
            ]);

        // Select the relative rate specifically
        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $relativeRate->id,
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($relativeRate->id, $availableDates->toArray());

        $dates = $availableDates[$relativeRate->id];

        // 100 * 0.80 = 80.00
        $this->assertEquals('80.00', $dates[today()->format('Y-m-d')]['price']);
    }

    public function test_date_list_shows_shared_rate_in_all_rates_mode()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // Show all rates — shared rate should appear
        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($baseRate->id, $availableDates->toArray());
        $this->assertArrayHasKey($sharedRate->id, $availableDates->toArray());

        // Both should show same base price (shared rate is not relative)
        $this->assertEquals('100.00', $availableDates[$baseRate->id][today()->format('Y-m-d')]['price']);
        $this->assertEquals('100.00', $availableDates[$sharedRate->id][today()->format('Y-m-d')]['price']);
    }

    public function test_date_list_shows_shared_relative_rate_with_modified_prices()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRelativeRate = Rate::factory()->shared()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($baseRate->id, $availableDates->toArray());
        $this->assertArrayHasKey($sharedRelativeRate->id, $availableDates->toArray());

        // Base rate: 100.00
        $this->assertEquals('100.00', $availableDates[$baseRate->id][today()->format('Y-m-d')]['price']);

        // Shared relative: 100 * 0.90 = 90.00
        $this->assertEquals('90.00', $availableDates[$sharedRelativeRate->id][today()->format('Y-m-d')]['price']);
    }

    public function test_date_list_filters_rate_failing_lead_time()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'advance-only',
            'min_days_before' => 14,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        // Search starting tomorrow — rate requires 14 days lead time
        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->addDay()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $rate->id,
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertEmpty($availableDates->toArray());
    }

    public function test_date_list_returns_later_dates_that_pass_lead_time()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'advance-only',
            'min_days_before' => 2,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 10);

        // Search starting today — rate requires 2 days lead time
        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $rate->id,
            ]);

        $availableDates = $component->viewData('availableDates');
        $dates = $availableDates[$rate->id] ?? [];

        // Today and tomorrow fail lead time (0 and 1 days)
        $this->assertArrayNotHasKey(today()->format('Y-m-d'), $dates);
        $this->assertArrayNotHasKey(today()->addDay()->format('Y-m-d'), $dates);

        // Day+2 onward pass lead time (2+ days)
        $this->assertArrayHasKey(today()->addDays(2)->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDays(3)->format('Y-m-d'), $dates);
    }

    public function test_date_list_group_by_date_includes_shared_rates()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'groupByDate' => true,
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        $todayKey = today()->format('Y-m-d');
        $this->assertArrayHasKey($todayKey, $availableDates->toArray());

        $todayData = $availableDates[$todayKey];
        $this->assertArrayHasKey($baseRate->id, $todayData);
        $this->assertArrayHasKey($sharedRate->id, $todayData);
    }

    public function test_date_list_excludes_restricted_rate_in_all_rates_mode()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $openRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'open-rate',
        ]);

        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-rate',
            'min_days_before' => 30,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $openRate->id, 4);
        $this->createAvailabilityForEntry($entry, 80, 2, $restrictedRate->id, 4);

        // Search starting tomorrow — restricted rate requires 30 days lead time
        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->addDay()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        // Open rate should appear, restricted should not
        $this->assertArrayHasKey($openRate->id, $availableDates->toArray());
        $this->assertArrayNotHasKey($restrictedRate->id, $availableDates->toArray());
    }

    public function test_date_list_preserves_shared_rate_id_in_specific_rate_search()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $sharedRate->id,
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($sharedRate->id, $availableDates->toArray());
        $this->assertArrayNotHasKey($baseRate->id, $availableDates->toArray());
    }

    public function test_date_list_shared_relative_rate_has_correct_id_and_price_in_specific_search()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRelativeRate = Rate::factory()->shared()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $this->createAvailabilityForEntry($entry, 200, 3, $baseRate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $sharedRelativeRate->id,
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($sharedRelativeRate->id, $availableDates->toArray());

        $dates = $availableDates[$sharedRelativeRate->id];
        // 200 * 0.75 = 150.00
        $this->assertEquals('150.00', $dates[today()->format('Y-m-d')]['price']);
    }

    public function test_date_list_excludes_expired_rate_in_specific_rate_search()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'expired-rate',
            'date_end' => today()->subDay(),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $rate->id,
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertEmpty($availableDates->toArray());
    }

    public function test_date_list_filters_dates_before_rate_date_start()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'future-rate',
            'date_start' => today()->addDays(2),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $rate->id,
            ]);

        $availableDates = $component->viewData('availableDates');
        $dates = $availableDates[$rate->id] ?? [];

        $this->assertArrayNotHasKey(today()->format('Y-m-d'), $dates);
        $this->assertArrayNotHasKey(today()->addDay()->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDays(2)->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDays(3)->format('Y-m-d'), $dates);
    }

    public function test_date_list_filters_dates_after_rate_date_end()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'ending-rate',
            'date_end' => today()->addDay(),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $rate->id,
            ]);

        $availableDates = $component->viewData('availableDates');
        $dates = $availableDates[$rate->id] ?? [];

        $this->assertArrayHasKey(today()->format('Y-m-d'), $dates);
        $this->assertArrayHasKey(today()->addDay()->format('Y-m-d'), $dates);
        $this->assertArrayNotHasKey(today()->addDays(2)->format('Y-m-d'), $dates);
        $this->assertArrayNotHasKey(today()->addDays(3)->format('Y-m-d'), $dates);
    }

    public function test_date_list_excludes_expired_rate_in_all_rates_mode()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $openRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'open-rate',
        ]);

        $expiredRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'expired-rate',
            'date_end' => today()->subDay(),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $openRate->id, 4);
        $this->createAvailabilityForEntry($entry, 80, 2, $expiredRate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertArrayHasKey($openRate->id, $availableDates->toArray());
        $this->assertArrayNotHasKey($expiredRate->id, $availableDates->toArray());
    }

    public function test_date_list_filters_dates_by_window_in_all_rates_mode()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $openRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'open-rate',
        ]);

        $windowedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'windowed-rate',
            'date_start' => today()->addDays(2),
            'date_end' => today()->addDays(3),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $openRate->id, 4);
        $this->createAvailabilityForEntry($entry, 80, 2, $windowedRate->id, 4);

        $component = Livewire::test(AvailabilityList::class, [
            'entry' => $entry->id(),
            'rates' => true,
        ])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => today()->toISOString(),
                    'date_end' => today()->addDays(30)->toISOString(),
                ],
                'quantity' => 1,
                'rate' => 'any',
            ]);

        $availableDates = $component->viewData('availableDates');

        $this->assertCount(4, $availableDates[$openRate->id]);

        $this->assertArrayHasKey($windowedRate->id, $availableDates->toArray());
        $windowedDates = $availableDates[$windowedRate->id];
        $this->assertCount(2, $windowedDates);
        $this->assertArrayHasKey(today()->addDays(2)->format('Y-m-d'), $windowedDates);
        $this->assertArrayHasKey(today()->addDays(3)->format('Y-m-d'), $windowedDates);
    }
}

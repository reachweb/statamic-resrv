<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Enums\RateSorting;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Tests\TestCase;

class RateSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    /**
     * Base independent rate (order 0, 100/day) plus a cheaper shared-independent
     * rate (order 1) whose price comes from RatePrice overrides (30/day). The
     * shared rate draws its availability from the base rate's pool, so both are
     * available, but ordered by price the shared rate is the cheapest.
     */
    protected function createCheaperHigherOrderSetup(): array
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'title' => 'Base',
            'order' => 0,
        ]);

        $sharedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'shared-rate',
            'title' => 'Shared',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'order' => 1,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        foreach ([0, 1] as $offset) {
            RatePrice::create([
                'rate_id' => $sharedRate->id,
                'statamic_id' => $entry->id(),
                'date' => $startDate->copy()->addDays($offset)->toDateString(),
                'price' => 30,
            ]);
        }

        return compact('entry', 'baseRate', 'sharedRate', 'startDate');
    }

    protected function searchData($startDate, array $extra = []): array
    {
        return array_merge([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ], $extra);
    }

    public function test_default_sorting_returns_lowest_order_rate()
    {
        $setup = $this->createCheaperHigherOrderSetup();

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($setup['startDate']),
            $setup['entry']->id(),
        );

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($setup['baseRate']->id, $result['data']['rate_id']);
        $this->assertEquals('200.00', $result['data']['price']);
    }

    public function test_explicit_order_sorting_matches_default()
    {
        $setup = $this->createCheaperHigherOrderSetup();

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($setup['startDate']),
            $setup['entry']->id(),
            rateSorting: RateSorting::Order,
        );

        $this->assertEquals($setup['baseRate']->id, $result['data']['rate_id']);
        $this->assertEquals('200.00', $result['data']['price']);
    }

    public function test_price_sorting_returns_cheapest_rate()
    {
        $setup = $this->createCheaperHigherOrderSetup();

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($setup['startDate']),
            $setup['entry']->id(),
            rateSorting: RateSorting::Price,
        );

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($setup['sharedRate']->id, $result['data']['rate_id']);
        $this->assertEquals('60.00', $result['data']['price']);
        // The cheapest rate must carry its own payment/deposit through, not the base rate's.
        $this->assertEquals('60.00', $result['data']['payment']);
    }

    public function test_single_rate_entry_is_identical_under_both_sortings()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'only-rate',
            'order' => 0,
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
                'price' => 100,
                'available' => 5,
            ]);

        $byOrder = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Order,
        );

        $byPrice = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Price,
        );

        $this->assertEquals($rate->id, $byOrder['data']['rate_id']);
        $this->assertEquals($byOrder['data']['rate_id'], $byPrice['data']['rate_id']);
        $this->assertEquals($byOrder['data']['price'], $byPrice['data']['price']);
        $this->assertEquals('200.00', $byPrice['data']['price']);
    }

    public function test_price_sorting_skips_unavailable_cheapest_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        // Pricier rate (order 0), fully available.
        $availableRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'available-rate',
            'order' => 0,
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $availableRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // Cheaper rate (order 1), but sold out for the requested dates.
        $soldOutRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'sold-out-rate',
            'order' => 1,
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $soldOutRate->id,
                'price' => 10,
                'available' => 0,
            ]);

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Price,
        );

        // The cheapest *available* rate wins, not the cheaper sold-out one.
        $this->assertTrue($result['message']['status']);
        $this->assertEquals($availableRate->id, $result['data']['rate_id']);
        $this->assertEquals('200.00', $result['data']['price']);
    }

    public function test_price_sorting_returns_not_available_when_nothing_is_available()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'sold-out',
            'order' => 0,
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
                'price' => 50,
                'available' => 0,
            ]);

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Price,
        );

        $this->assertFalse($result['message']['status']);
        $this->assertEmpty($result['data']);
    }

    public function test_explicit_rate_is_unaffected_by_price_sorting()
    {
        $setup = $this->createCheaperHigherOrderSetup();

        // A specifically requested (pricier) rate must be honored even when
        // price sorting is requested — the explicit rate always wins.
        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($setup['startDate'], ['rate_id' => $setup['baseRate']->id]),
            $setup['entry']->id(),
            rateSorting: RateSorting::Price,
        );

        $this->assertEquals($setup['baseRate']->id, $result['data']['rate_id']);
        $this->assertEquals('200.00', $result['data']['price']);
    }

    public function test_price_sorting_breaks_ties_by_rate_order()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        $firstRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'first-rate',
            'order' => 0,
        ]);

        $secondRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'second-rate',
            'order' => 1,
        ]);

        foreach ([$firstRate, $secondRate] as $rate) {
            Availability::factory()
                ->count(2)
                ->sequence(
                    ['date' => $startDate],
                    ['date' => $startDate->copy()->addDay()],
                )
                ->create([
                    'statamic_id' => $entry->id(),
                    'rate_id' => $rate->id,
                    'price' => 80,
                    'available' => 5,
                ]);
        }

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Price,
        );

        // Equal prices resolve deterministically to the lowest-order rate.
        $this->assertEquals($firstRate->id, $result['data']['rate_id']);
        $this->assertEquals('160.00', $result['data']['price']);
    }

    public function test_rate_sorting_enum_normalizes_unknown_values_to_order()
    {
        $this->assertSame(RateSorting::Order, RateSorting::fromValue('garbage'));
        $this->assertSame(RateSorting::Order, RateSorting::fromValue(null));
        $this->assertSame(RateSorting::Order, RateSorting::fromValue('order'));
        $this->assertSame(RateSorting::Price, RateSorting::fromValue('price'));
    }
}

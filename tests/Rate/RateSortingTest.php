<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Enums\RateSorting;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class RateSortingTest extends TestCase
{
    use CreatesEntries;

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

        $this->createAvailabilityForEntry($entry, 100, 5, $baseRate->id, 2, from: $startDate);

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

        $this->createAvailabilityForEntry($entry, 100, 5, $rate->id, 2, from: $startDate);

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

    public function test_order_sorting_skips_first_rate_that_fails_restrictions()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        // Lowest-order rate, but its min_stay rejects the 2-night search below.
        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-rate',
            'order' => 0,
            'min_stay' => 3,
        ]);

        // Next rate by order, bookable for the search.
        $bookableRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'bookable-rate',
            'order' => 1,
        ]);

        foreach ([$restrictedRate, $bookableRate] as $rate) {
            $this->createAvailabilityForEntry($entry, 100, 5, $rate->id, 2, from: $startDate);
        }

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Order,
        );

        // Same rate the listing (getAvailable) and price sorting return for this search.
        $this->assertTrue($result['message']['status']);
        $this->assertEquals($bookableRate->id, $result['data']['rate_id']);
        $this->assertEquals('200.00', $result['data']['price']);
    }

    // The detail page picks the rate the listing shows first. A shared rate can come before its
    // base and before another rate, and it is still the first bookable one.
    public function test_order_sorting_returns_a_shared_rate_ordered_before_its_base()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        $base = Rate::factory()->create(['collection' => 'pages', 'slug' => 'base', 'order' => 2]);
        $other = Rate::factory()->create(['collection' => 'pages', 'slug' => 'other', 'order' => 1]);
        $shared = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'shared',
            'order' => 0,
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $base->id,
        ]);

        foreach ([[$base, 100], [$other, 80]] as [$rate, $price]) {
            $this->createAvailabilityForEntry($entry, $price, 5, $rate->id, 2, from: $startDate);
        }

        foreach ([0, 1] as $day) {
            RatePrice::create([
                'rate_id' => $shared->id,
                'statamic_id' => $entry->id(),
                'date' => $startDate->copy()->addDays($day)->toDateString(),
                'price' => 30,
            ]);
        }

        $result = (new Availability)->getAvailabilityForEntry($this->searchData($startDate), $entry->id(), rateSorting: RateSorting::Order);

        $this->assertEquals($shared->id, $result['data']['rate_id']);
        $this->assertEquals('60.00', $result['data']['price']);
    }

    public function test_order_sorting_stops_at_the_first_bookable_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        $baseRate = Rate::factory()->create(['collection' => 'pages', 'slug' => 'base', 'order' => 0]);

        $this->createAvailabilityForEntry($entry, 100, 5, $baseRate->id, 2, from: $startDate);

        // Capped shared rates after the bookable base rate: checking one costs extra queries.
        $addCappedRates = fn (int $count, int $from) => Rate::factory()->count($count)->sequence(
            fn ($sequence) => ['slug' => 'capped-'.($from + $sequence->index), 'order' => $from + $sequence->index],
        )->create([
            'collection' => 'pages',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'max_available' => 2,
        ]);

        $queriesForSearch = function () use ($entry, $startDate) {
            $search = fn () => (new Availability)->getAvailabilityForEntry($this->searchData($startDate), $entry->id(), rateSorting: RateSorting::Order);
            $search();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $search();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$count, $result['data']['rate_id']];
        };

        $addCappedRates(1, 1);
        [$withOneLaterRate, $rateId] = $queriesForSearch();
        $this->assertEquals($baseRate->id, $rateId);

        $addCappedRates(3, 2);
        [$withFourLaterRates, $rateId] = $queriesForSearch();
        $this->assertEquals($baseRate->id, $rateId);

        $this->assertSame($withOneLaterRate, $withFourLaterRates);
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

        $this->createAvailabilityForEntry($entry, 100, 5, $availableRate->id, 2, from: $startDate);

        // Cheaper rate (order 1), but sold out for the requested dates.
        $soldOutRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'sold-out-rate',
            'order' => 1,
        ]);

        $this->createAvailabilityForEntry($entry, 10, 0, $soldOutRate->id, 2, from: $startDate);

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

        $this->createAvailabilityForEntry($entry, 50, 0, $rate->id, 2, from: $startDate);

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

    public function test_price_sorting_breaks_ties_by_rate_order_not_id()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();
        $startDate = now()->startOfDay();

        // Created first, so it gets the LOWER id but the HIGHER order.
        $higherOrderRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'higher-order-rate',
            'order' => 1,
        ]);

        // Created second, so it gets the HIGHER id but the LOWER order.
        $lowerOrderRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'lower-order-rate',
            'order' => 0,
        ]);

        foreach ([$higherOrderRate, $lowerOrderRate] as $rate) {
            $this->createAvailabilityForEntry($entry, 80, 5, $rate->id, 2, from: $startDate);
        }

        $result = (new Availability)->getAvailabilityForEntry(
            $this->searchData($startDate),
            $entry->id(),
            rateSorting: RateSorting::Price,
        );

        // Equal prices resolve to the lowest-ORDER rate (order 0) even though it has the
        // higher id — proving the tie-break follows rate order, not id/insertion, and does
        // not depend on sort stability.
        $this->assertEquals($lowerOrderRate->id, $result['data']['rate_id']);
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

<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class RateAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    protected function createEntryWithRateAndAvailability(
        float $price = 100.00,
        int $available = 2,
        array $rateAttributes = [],
        int $days = 4
    ): array {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(array_merge(
            ['collection' => 'pages'],
            $rateAttributes,
        ));

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count($days)
            ->sequence(fn ($sequence) => [
                'date' => $startDate->copy()->addDays($sequence->index),
                'price' => $price,
                'available' => $available,
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
            ])
            ->create();

        return ['entry' => $entry, 'rate' => $rate];
    }

    public function test_availability_query_filters_by_rate_id()
    {
        $data = $this->createEntryWithRateAndAvailability();

        $results = AvailabilityRepository::itemAvailableBetween(
            date_start: now()->startOfDay()->toDateString(),
            date_end: now()->startOfDay()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $data['entry']->id(),
            rateId: $data['rate']->id,
        )->get();

        $this->assertCount(1, $results);
        $this->assertEquals($data['entry']->id(), $results->first()->statamic_id);
    }

    public function test_availability_query_returns_empty_for_wrong_rate()
    {
        $data = $this->createEntryWithRateAndAvailability();

        $results = AvailabilityRepository::itemAvailableBetween(
            date_start: now()->startOfDay()->toDateString(),
            date_end: now()->startOfDay()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $data['entry']->id(),
            rateId: 9999,
        )->get();

        $this->assertCount(0, $results);
    }

    public function test_relative_rate_price_calculation_percent_decrease()
    {
        [$entry, , $relativeRate] = $this->createRelativePricingSetup(
            modifierType: 'percent',
            modifierOperation: 'decrease',
            modifierAmount: 20,
        );

        // 2 days * 100 base * 0.80 (20% decrease) = 160
        $this->assertEquals('160.00', $this->getPricingForRate($relativeRate, $entry));
    }

    public function test_relative_rate_price_calculation_fixed_increase()
    {
        [$entry, , $relativeRate] = $this->createRelativePricingSetup(
            modifierType: 'fixed',
            modifierOperation: 'increase',
            modifierAmount: 20,
        );

        // 2 days * (100 + 20) = 240
        $this->assertEquals('240.00', $this->getPricingForRate($relativeRate, $entry));
    }

    public function test_independent_rate_pricing_unchanged()
    {
        $data = $this->createEntryWithRateAndAvailability(days: 2);

        // 2 days * 100 = 200 (no modification)
        $this->assertEquals('200.00', $this->getPricingForRate($data['rate'], $data['entry']));
    }

    protected function createRelativePricingSetup(
        string $modifierType,
        string $modifierOperation,
        int $modifierAmount,
        float $basePrice = 100.00,
    ): array {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard',
        ]);

        $relativeRate = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => $modifierType,
            'modifier_operation' => $modifierOperation,
            'modifier_amount' => $modifierAmount,
        ]);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => now()->startOfDay()],
                ['date' => now()->startOfDay()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $relativeRate->id,
                'price' => $basePrice,
                'available' => 2,
            ]);

        return [$entry, $baseRate, $relativeRate];
    }

    protected function getPricingForRate(Rate $rate, $entry): string
    {
        return (new Availability)->getPricing([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $rate->id,
        ], $entry->id(), true);
    }

    public function test_rate_date_restrictions()
    {
        $rate = Rate::factory()->create([
            'date_start' => now()->addDay()->toDateString(),
            'date_end' => now()->addMonth()->toDateString(),
        ]);

        // Starts before date_start
        $this->assertFalse($rate->isAvailableForDates(
            now()->toDateString(),
            now()->addWeek()->toDateString()
        ));

        // Ends after date_end
        $this->assertFalse($rate->isAvailableForDates(
            now()->addDays(2)->toDateString(),
            now()->addMonths(2)->toDateString()
        ));

        // Within range
        $this->assertTrue($rate->isAvailableForDates(
            now()->addDays(2)->toDateString(),
            now()->addWeek()->toDateString()
        ));
    }

    public function test_rate_date_restrictions_with_exclusive_checkout()
    {
        $rate = Rate::factory()->create([
            'date_start' => now()->toDateString(),
            'date_end' => now()->addDays(3)->toDateString(),
        ]);

        // 2-night stay: last occupied night is date_end, checkout is date_end + 1
        $this->assertTrue($rate->isAvailableForDates(
            now()->addDay()->toDateString(),
            now()->addDays(4)->toDateString()
        ));

        // Checkout exactly on date_end (last night is date_end - 1)
        $this->assertTrue($rate->isAvailableForDates(
            now()->addDay()->toDateString(),
            now()->addDays(3)->toDateString()
        ));

        // Last occupied night is one day past date_end — should fail
        $this->assertFalse($rate->isAvailableForDates(
            now()->addDay()->toDateString(),
            now()->addDays(5)->toDateString()
        ));
    }

    public function test_rate_min_stay_restriction()
    {
        $rate = Rate::factory()->create(['min_stay' => 3]);

        $this->assertFalse($rate->meetsStayRestrictions(2));
        $this->assertTrue($rate->meetsStayRestrictions(3));
        $this->assertTrue($rate->meetsStayRestrictions(5));
    }

    public function test_rate_max_stay_restriction()
    {
        $rate = Rate::factory()->create(['max_stay' => 7]);

        $this->assertTrue($rate->meetsStayRestrictions(5));
        $this->assertTrue($rate->meetsStayRestrictions(7));
        $this->assertFalse($rate->meetsStayRestrictions(8));
    }

    public function test_rate_min_days_before_restriction()
    {
        $rate = Rate::factory()->create(['min_days_before' => 3]);

        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDay()->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(5)->toDateString()));
    }

    public function test_rate_max_days_before_restriction()
    {
        $rate = Rate::factory()->create(['max_days_before' => 7]);

        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(3)->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(7)->toDateString()));
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDays(14)->toDateString()));
    }

    public function test_rate_combined_lead_time_restrictions()
    {
        $rate = Rate::factory()->create([
            'min_days_before' => 2,
            'max_days_before' => 10,
        ]);

        // Too soon
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDay()->toDateString()));
        // Within range
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(5)->toDateString()));
        // Too far out
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDays(15)->toDateString()));
    }

    public function test_fixed_pricing_filtered_by_rate_id()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate1 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard',
            'order' => 0,
        ]);
        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'premium',
            'order' => 1,
        ]);

        $startDate = now()->startOfDay();

        foreach ([$rate1, $rate2] as $rate) {
            Availability::factory()
                ->count(3)
                ->sequence(
                    ['date' => $startDate],
                    ['date' => $startDate->copy()->addDay()],
                    ['date' => $startDate->copy()->addDays(2)],
                )
                ->create([
                    'statamic_id' => $entry->id(),
                    'rate_id' => $rate->id,
                    'price' => 100,
                    'available' => 2,
                ]);
        }

        // Different fixed pricing per rate: 3-day stay
        FixedPricing::factory()->create([
            'statamic_id' => $entry->id(),
            'rate_id' => $rate1->id,
            'days' => 3,
            'price' => '250.00',
        ]);
        FixedPricing::factory()->create([
            'statamic_id' => $entry->id(),
            'rate_id' => $rate2->id,
            'days' => 3,
            'price' => '400.00',
        ]);

        // Rate 1 should get 250
        $priceRate1 = (new Availability)->getPricing([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rate_id' => $rate1->id,
        ], $entry->id(), true);

        $this->assertEquals('250.00', $priceRate1);

        // Rate 2 should get 400
        $priceRate2 = (new Availability)->getPricing([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rate_id' => $rate2->id,
        ], $entry->id(), true);

        $this->assertEquals('400.00', $priceRate2);
    }

    public function test_all_rates_query_returns_multiple_rates()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate1 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard',
            'order' => 0,
        ]);
        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'premium',
            'order' => 1,
        ]);

        $startDate = now()->startOfDay();

        foreach ([$rate1, $rate2] as $rate) {
            Availability::factory()
                ->count(2)
                ->sequence(
                    ['date' => $startDate],
                    ['date' => $startDate->copy()->addDay()],
                )
                ->create([
                    'statamic_id' => $entry->id(),
                    'rate_id' => $rate->id,
                    'price' => $rate->slug === 'standard' ? 100 : 150,
                    'available' => 2,
                ]);
        }

        $results = AvailabilityRepository::itemAvailableBetweenForAllRates(
            date_start: $startDate->toDateString(),
            date_end: $startDate->copy()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $entry->id(),
        )->get();

        $this->assertCount(2, $results);
    }

    public function test_all_rates_view_includes_shared_rate()
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

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);

        // Resource re-indexes integer keys; re-key by rate_id like Livewire does
        $data = collect($result['data'])->keyBy('rate_id');
        $this->assertTrue($data->has($baseRate->id));
        $this->assertTrue($data->has($sharedRate->id));
    }

    public function test_all_rates_view_applies_relative_pricing_modifier()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $relativeRate = Rate::factory()->relative()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 20,
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

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);

        $data = collect($result['data'])->keyBy('rate_id');

        // Base rate: 2 * 100 = 200
        $this->assertEquals('200.00', $data->get($baseRate->id)['price']);

        // Relative rate: 2 * 80 (20% decrease) = 160
        $this->assertEquals('160.00', $data->get($relativeRate->id)['price']);
    }

    public function test_all_rates_view_filters_rate_failing_date_restriction()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $validRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'valid-rate',
        ]);

        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-rate',
            'date_start' => now()->addMonth()->toDateString(),
            'date_end' => now()->addMonths(3)->toDateString(),
        ]);

        $startDate = now()->startOfDay();

        foreach ([$validRate, $restrictedRate] as $rate) {
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
        }

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);

        // Only the valid rate should appear (single result = flat data)
        $this->assertEquals($validRate->id, $result['data']['rate_id']);
    }

    public function test_all_rates_view_filters_rate_failing_stay_restriction()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $validRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'valid-rate',
        ]);

        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-rate',
            'min_stay' => 5,
        ]);

        $startDate = now()->startOfDay();

        foreach ([$validRate, $restrictedRate] as $rate) {
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
        }

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($validRate->id, $result['data']['rate_id']);
    }

    public function test_all_rates_view_filters_rate_failing_lead_time_restriction()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $validRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'valid-rate',
        ]);

        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-rate',
            'min_days_before' => 30,
        ]);

        $startDate = now()->startOfDay();

        foreach ([$validRate, $restrictedRate] as $rate) {
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
        }

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($validRate->id, $result['data']['rate_id']);
    }

    public function test_single_rate_query_rejects_rate_failing_date_restriction()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'restricted-rate',
                'date_start' => now()->addMonth()->toDateString(),
                'date_end' => now()->addMonths(3)->toDateString(),
            ],
        );

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $data['rate']->id,
        ], $data['entry']->id());

        $this->assertFalse($result['message']['status']);
    }

    public function test_single_rate_query_rejects_rate_failing_stay_restriction()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'restricted-rate',
                'min_stay' => 5,
            ],
        );

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $data['rate']->id,
        ], $data['entry']->id());

        $this->assertFalse($result['message']['status']);
    }

    public function test_confirm_availability_rejects_rate_failing_restrictions()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'restricted-rate',
                'min_stay' => 5,
            ],
        );

        $result = (new Availability)->confirmAvailabilityAndPrice([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $data['rate']->id,
            'price' => '200.00',
        ], $data['entry']->id());

        $this->assertFalse($result);
    }

    public function test_single_rate_query_rejects_unpublished_rate()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'unpublished-rate',
                'published' => false,
            ],
        );

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $data['rate']->id,
        ], $data['entry']->id());

        $this->assertFalse($result['message']['status']);
    }

    public function test_confirm_availability_rejects_unpublished_rate()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'unpublished-rate',
                'published' => false,
            ],
        );

        $result = (new Availability)->confirmAvailabilityAndPrice([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $data['rate']->id,
            'price' => '200.00',
        ], $data['entry']->id());

        $this->assertFalse($result);
    }

    public function test_all_rates_view_excludes_unpublished_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $publishedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'published-rate',
            'published' => true,
        ]);

        $unpublishedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'unpublished-rate',
            'published' => false,
        ]);

        $startDate = now()->startOfDay();

        foreach ([$publishedRate, $unpublishedRate] as $rate) {
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
        }

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($publishedRate->id, $result['data']['rate_id']);
    }

    public function test_single_item_query_without_rate_id_rejects_unpublished_rate()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'unpublished-rate',
                'published' => false,
            ],
        );

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
        ], $data['entry']->id());

        $this->assertFalse($result['message']['status']);
    }

    public function test_confirm_availability_without_rate_id_rejects_unpublished_rate()
    {
        $data = $this->createEntryWithRateAndAvailability(
            days: 2,
            rateAttributes: [
                'slug' => 'unpublished-rate',
                'published' => false,
            ],
        );

        $result = (new Availability)->confirmAvailabilityAndPrice([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
            'price' => '200.00',
        ], $data['entry']->id());

        $this->assertFalse($result);
    }

    public function test_single_item_query_without_rate_id_returns_published_rate()
    {
        $data = $this->createEntryWithRateAndAvailability(days: 2);

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => now()->startOfDay()->toDateString(),
            'date_end' => now()->startOfDay()->addDays(2)->toDateString(),
            'quantity' => 1,
        ], $data['entry']->id());

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($data['rate']->id, $result['data']['rate_id']);
    }
}

<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
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

        $results = AvailabilityRepository::itemAvailableBetweenForRate(
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

        $results = AvailabilityRepository::itemAvailableBetweenForRate(
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
                'rate_id' => $baseRate->id,
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
}

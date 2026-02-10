<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class RateCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_list_rates_for_entry_empty()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->get(cp_route('resrv.rate.index', $item->id()));
        $response->assertStatus(200)->assertJson([]);
    }

    public function test_can_list_rates_for_entry()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create(['statamic_id' => $item->id()]);

        $response = $this->get(cp_route('resrv.rate.index', $item->id()));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_can_create_independent_rate()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'statamic_id' => $item->id(),
            'title' => 'Standard Room',
            'slug' => 'standard-room',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'refundable' => true,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200)->assertJsonStructure(['id']);

        $this->assertDatabaseHas('resrv_rates', [
            'statamic_id' => $item->id(),
            'slug' => 'standard-room',
        ]);
    }

    public function test_can_create_relative_rate()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['statamic_id' => $item->id()]);

        $payload = [
            'statamic_id' => $item->id(),
            'title' => 'Discounted Rate',
            'slug' => 'discounted-rate',
            'pricing_type' => 'relative',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 20,
            'availability_type' => 'independent',
            'refundable' => true,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'discounted-rate',
            'pricing_type' => 'relative',
            'base_rate_id' => $baseRate->id,
        ]);
    }

    public function test_rejects_duplicate_slug_for_same_entry()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create([
            'statamic_id' => $item->id(),
            'slug' => 'standard-room',
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'title' => 'Another Standard Room',
            'slug' => 'standard-room',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422);
    }

    public function test_allows_same_slug_for_different_entries()
    {
        $item1 = $this->makeStatamicItemWithResrvAvailabilityField();
        $item2 = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create([
            'statamic_id' => $item1->id(),
            'slug' => 'standard-room',
        ]);

        $payload = [
            'statamic_id' => $item2->id(),
            'title' => 'Standard Room',
            'slug' => 'standard-room',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);
    }

    public function test_rejects_relative_rate_without_base_rate_id()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'statamic_id' => $item->id(),
            'title' => 'Bad Rate',
            'slug' => 'bad-rate',
            'pricing_type' => 'relative',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422);
    }

    public function test_rejects_base_rate_from_different_entry()
    {
        $this->withExceptionHandling();

        $item1 = $this->makeStatamicItemWithResrvAvailabilityField();
        $item2 = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['statamic_id' => $item1->id()]);

        $payload = [
            'statamic_id' => $item2->id(),
            'title' => 'Bad Rate',
            'slug' => 'bad-rate',
            'pricing_type' => 'relative',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422);
    }

    public function test_can_update_a_rate()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['statamic_id' => $item->id()]);

        $payload = [
            'statamic_id' => $item->id(),
            'title' => 'Updated Title',
            'slug' => 'standard-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_can_soft_delete_a_rate()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['statamic_id' => $item->id()]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(200);

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);
    }

    public function test_cannot_delete_rate_that_is_base_for_other_rates()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['statamic_id' => $item->id()]);

        Rate::factory()->create([
            'statamic_id' => $item->id(),
            'slug' => 'dependent-rate',
            'pricing_type' => 'relative',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $baseRate->id));
        $response->assertStatus(422);

        $this->assertDatabaseHas('resrv_rates', ['id' => $baseRate->id, 'deleted_at' => null]);
    }

    public function test_can_reorder_rates()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate1 = Rate::factory()->create([
            'statamic_id' => $item->id(),
            'slug' => 'rate-1',
            'order' => 0,
        ]);
        $rate2 = Rate::factory()->create([
            'statamic_id' => $item->id(),
            'slug' => 'rate-2',
            'order' => 1,
        ]);

        $payload = [
            ['id' => $rate1->id, 'order' => 1],
            ['id' => $rate2->id, 'order' => 0],
        ];

        $response = $this->post(cp_route('resrv.rate.order'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', ['id' => $rate1->id, 'order' => 1]);
        $this->assertDatabaseHas('resrv_rates', ['id' => $rate2->id, 'order' => 0]);
    }

    public function test_calculate_price_percent_decrease()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 20,
        ]);

        $basePrice = Price::create('100.00');
        $result = $rate->calculatePrice($basePrice);

        $this->assertEquals('80.00', $result->format());
    }

    public function test_calculate_price_percent_increase()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'percent',
            'modifier_operation' => 'increase',
            'modifier_amount' => 25,
        ]);

        $basePrice = Price::create('100.00');
        $result = $rate->calculatePrice($basePrice);

        $this->assertEquals('125.00', $result->format());
    }

    public function test_calculate_price_fixed_decrease()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'fixed',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 15,
        ]);

        $basePrice = Price::create('100.00');
        $result = $rate->calculatePrice($basePrice);

        $this->assertEquals('85.00', $result->format());
    }

    public function test_calculate_price_fixed_increase()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'fixed',
            'modifier_operation' => 'increase',
            'modifier_amount' => 20,
        ]);

        $basePrice = Price::create('100.00');
        $result = $rate->calculatePrice($basePrice);

        $this->assertEquals('120.00', $result->format());
    }

    public function test_is_available_for_dates()
    {
        $rate = Rate::factory()->withRestrictions()->create([
            'date_start' => now()->subDay(),
            'date_end' => now()->addMonth(),
        ]);

        $this->assertTrue($rate->isAvailableForDates(now()->toDateString(), now()->addWeek()->toDateString()));
        $this->assertFalse($rate->isAvailableForDates(now()->subMonth()->toDateString(), now()->toDateString()));
        $this->assertFalse($rate->isAvailableForDates(now()->toDateString(), now()->addMonths(2)->toDateString()));
    }

    public function test_meets_stay_restrictions()
    {
        $rate = Rate::factory()->withRestrictions()->create([
            'min_stay' => 3,
            'max_stay' => 7,
        ]);

        $this->assertTrue($rate->meetsStayRestrictions(3));
        $this->assertTrue($rate->meetsStayRestrictions(5));
        $this->assertTrue($rate->meetsStayRestrictions(7));
        $this->assertFalse($rate->meetsStayRestrictions(2));
        $this->assertFalse($rate->meetsStayRestrictions(8));
    }

    public function test_meets_booking_lead_time()
    {
        $rate = Rate::factory()->withRestrictions()->create([
            'min_days_before' => 3,
        ]);

        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(5)->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(3)->toDateString()));
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDays(1)->toDateString()));
        $this->assertFalse($rate->meetsBookingLeadTime(now()->toDateString()));
    }
}

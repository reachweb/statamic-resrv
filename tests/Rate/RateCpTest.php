<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class RateCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_list_rates_empty()
    {
        $response = $this->get(cp_route('resrv.rate.index'));
        $response->assertStatus(200)->assertJson([]);
    }

    public function test_can_list_rates_filtered_by_collection()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create(['collection' => 'pages']);

        $response = $this->get(cp_route('resrv.rate.index', ['collection' => 'pages']));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_can_list_collections()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->get(cp_route('resrv.rate.collections'));
        $response->assertStatus(200)->assertJsonFragment(['handle' => 'pages']);
    }

    public function test_can_list_entries_for_collection()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $response = $this->get(cp_route('resrv.rate.entries', 'pages'));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_can_get_rates_for_entry()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create(['collection' => 'pages']);

        $response = $this->get(cp_route('resrv.rate.forEntry', $item->id()));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_for_entry_returns_apply_to_all_rates()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create(['collection' => 'pages', 'apply_to_all' => true]);

        $response = $this->get(cp_route('resrv.rate.forEntry', $item->id()));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_for_entry_returns_specifically_assigned_rates()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'apply_to_all' => false,
        ]);

        // Assign via pivot (attach using item_id, which is the relatedKey)
        $rate->entries()->attach($item->id());

        $response = $this->get(cp_route('resrv.rate.forEntry', $item->id()));
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_for_entry_excludes_unassigned_specific_rates()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create([
            'collection' => 'pages',
            'apply_to_all' => false,
        ]);

        // Not assigned to this entry
        $response = $this->get(cp_route('resrv.rate.forEntry', $item->id()));
        $response->assertStatus(200)->assertJsonCount(0);
    }

    public function test_can_create_independent_rate()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
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
            'collection' => 'pages',
            'slug' => 'standard-room',
        ]);
    }

    public function test_can_create_rate_with_specific_entries()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => false,
            'entries' => [$item->id()],
            'title' => 'Specific Rate',
            'slug' => 'specific-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'refundable' => true,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $rate = Rate::where('slug', 'specific-rate')->first();
        $this->assertFalse($rate->apply_to_all);
        $this->assertCount(1, $rate->entries);
    }

    public function test_can_create_relative_rate()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
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

    public function test_rejects_duplicate_slug_for_same_collection()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard-room',
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Another Standard Room',
            'slug' => 'standard-room',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422);
    }

    public function test_allows_same_slug_for_different_collections()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();
        $this->makeStatamicItemWithResrvAvailabilityField(collectionHandle: 'rooms');

        Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard-room',
        ]);

        $payload = [
            'collection' => 'rooms',
            'apply_to_all' => true,
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

        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
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

    public function test_rejects_base_rate_from_different_collection()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();
        $this->makeStatamicItemWithResrvAvailabilityField(collectionHandle: 'rooms');

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'rooms',
            'apply_to_all' => true,
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
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
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

    public function test_can_delete_a_rate()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_rates', ['id' => $rate->id]);
    }

    public function test_can_recreate_rate_with_same_slug_after_deletion()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'standard-room',
        ]);

        $this->delete(cp_route('resrv.rate.destroy', $rate->id));

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Standard Room',
            'slug' => 'standard-room',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200)->assertJsonStructure(['id']);
    }

    public function test_cannot_delete_rate_that_is_base_for_other_rates()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        Rate::factory()->create([
            'collection' => 'pages',
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

    public function test_rejects_self_referencing_base_rate()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Self Ref Rate',
            'slug' => 'standard-rate',
            'pricing_type' => 'relative',
            'base_rate_id' => $rate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->patchJson(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('base_rate_id');
    }

    public function test_can_reorder_rates()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate1 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'rate-1',
            'order' => 0,
        ]);
        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
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

    public function test_meets_booking_lead_time_with_max_days_before()
    {
        $rate = Rate::factory()->create([
            'max_days_before' => 7,
        ]);

        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(5)->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(7)->toDateString()));
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDays(10)->toDateString()));
    }

    public function test_meets_booking_lead_time_with_min_and_max_days_before()
    {
        $rate = Rate::factory()->create([
            'min_days_before' => 2,
            'max_days_before' => 7,
        ]);

        // Within range
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(3)->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(2)->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(7)->toDateString()));

        // Too soon (below min)
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDay()->toDateString()));

        // Too far ahead (above max)
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDays(10)->toDateString()));
    }

    public function test_index_returns_assigned_entries_for_specific_rates()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'apply_to_all' => false,
        ]);

        $rate->entries()->attach($item->id());

        $response = $this->get(cp_route('resrv.rate.index', ['collection' => 'pages']));
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data[0]['entries']);
        $this->assertEquals($item->id(), $data[0]['entries'][0]['item_id']);
    }

    public function test_updating_specific_rate_preserves_entry_assignments()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'apply_to_all' => false,
        ]);

        $rate->entries()->attach($item->id());

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => false,
            'entries' => [$item->id()],
            'title' => 'Updated Title',
            'slug' => 'standard-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rate_entries', [
            'rate_id' => $rate->id,
            'statamic_id' => $item->id(),
        ]);
    }

    public function test_updating_rate_preserves_single_sided_date_start()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'date_start' => '2026-06-01',
            'date_end' => null,
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Updated Title',
            'slug' => 'standard-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'date_start' => '2026-06-01',
            'date_end' => null,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        $rate->refresh();
        $this->assertNotNull($rate->date_start);
        $this->assertNull($rate->date_end);
    }

    public function test_max_days_before_validation()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Test Rate',
            'slug' => 'test-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'max_days_before' => -1,
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('max_days_before');
    }

    public function test_can_delete_rate_with_availability_rows()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $startDate = now()->startOfDay();

        Availability::factory()->count(3)->sequence(
            ['date' => $startDate],
            ['date' => $startDate->copy()->addDay()],
            ['date' => $startDate->copy()->addDays(2)],
        )->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'price' => 100,
            'available' => 2,
        ]);

        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'days' => 3,
            'price' => '250.00',
        ]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(200);

        $this->assertDatabaseMissing('resrv_rates', ['id' => $rate->id]);
        $this->assertDatabaseMissing('resrv_availabilities', ['rate_id' => $rate->id]);
        $this->assertDatabaseMissing('resrv_fixed_pricing', ['rate_id' => $rate->id]);
    }

    public function test_cannot_delete_rate_with_availability_and_active_reservations()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $startDate = now()->startOfDay();

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => $startDate,
            'price' => 100,
            'available' => 2,
        ]);

        Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(422);

        // Rate and availability should still exist
        $this->assertDatabaseHas('resrv_rates', ['id' => $rate->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('resrv_availabilities', ['rate_id' => $rate->id]);
    }
}

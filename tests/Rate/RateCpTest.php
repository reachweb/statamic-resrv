<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
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

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);
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
            'order' => 1,
        ]);
        $rate2 = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'rate-2',
            'order' => 2,
        ]);

        $response = $this->patchJson(cp_route('resrv.rate.order', $rate1->id), ['order' => 2]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', ['id' => $rate1->id, 'order' => 2]);
        $this->assertDatabaseHas('resrv_rates', ['id' => $rate2->id, 'order' => 1]);
    }

    public function test_reorder_keeps_child_within_parent()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $parent = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'parent',
            'order' => 1,
        ]);
        $otherParent = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'other-parent',
            'order' => 2,
        ]);
        $child1 = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'slug' => 'child-1',
            'base_rate_id' => $parent->id,
            'order' => 1,
        ]);
        $child2 = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'slug' => 'child-2',
            'base_rate_id' => $parent->id,
            'order' => 2,
        ]);
        $otherChild = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'slug' => 'other-child',
            'base_rate_id' => $otherParent->id,
            'order' => 1,
        ]);

        $response = $this->patchJson(cp_route('resrv.rate.order', $child1->id), ['order' => 2]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', ['id' => $child1->id, 'base_rate_id' => $parent->id, 'order' => 2]);
        $this->assertDatabaseHas('resrv_rates', ['id' => $child2->id, 'base_rate_id' => $parent->id, 'order' => 1]);
        $this->assertDatabaseHas('resrv_rates', ['id' => $otherChild->id, 'base_rate_id' => $otherParent->id, 'order' => 1]);
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

    public function test_calculate_price_percent_decrease_floors_at_zero()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 150,
        ]);

        // A >100% decrease must not produce a negative price.
        $result = $rate->calculatePrice(Price::create('100.00'));

        $this->assertEquals('0.00', $result->format());
    }

    public function test_calculate_price_fixed_decrease_floors_at_zero()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'fixed',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 150,
        ]);

        // A flat decrease larger than the base must clamp to zero, not go negative.
        $result = $rate->calculatePrice(Price::create('100.00'));

        $this->assertEquals('0.00', $result->format());
    }

    public function test_calculate_total_price_fixed_decrease_floors_at_zero()
    {
        $rate = Rate::factory()->relative()->create([
            'modifier_type' => 'fixed',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 60,
        ]);

        // 60/day over 2 days = 120 decrease on a 100 total — clamps to zero.
        $result = $rate->calculateTotalPrice(Price::create('100.00'), 2);

        $this->assertEquals('0.00', $result->format());
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

    public function test_meets_booking_lead_time_with_zero_max_days_before_means_same_day_only()
    {
        $rate = Rate::factory()->create([
            'max_days_before' => 0,
        ]);

        $this->assertTrue($rate->meetsBookingLeadTime(now()->toDateString()));
        $this->assertFalse($rate->meetsBookingLeadTime(now()->addDay()->toDateString()));
    }

    public function test_meets_booking_lead_time_with_zero_min_days_before_is_no_restriction()
    {
        $rate = Rate::factory()->create([
            'min_days_before' => 0,
        ]);

        $this->assertTrue($rate->meetsBookingLeadTime(now()->toDateString()));
        $this->assertTrue($rate->meetsBookingLeadTime(now()->addDays(30)->toDateString()));
    }

    public function test_can_create_rate_with_free_cancellation_policy()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Flexible Rate',
            'slug' => 'flexible-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'flexible-rate',
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
            'refundable' => true,
        ]);
    }

    public function test_can_create_non_refundable_rate_and_refundable_flag_is_derived()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Non-Refundable Rate',
            'slug' => 'non-refundable-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'cancellation_policy' => 'non_refundable',
            // A stale period must be dropped server-side for policies that don't use one.
            'free_cancellation_period' => 7,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'non-refundable-rate',
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
            'refundable' => false,
        ]);
    }

    public function test_can_update_rate_back_to_inherited_cancellation_policy()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->nonRefundable()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => $rate->title,
            'slug' => $rate->slug,
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
            'refundable' => true,
        ]);
    }

    public function test_legacy_payload_with_only_the_refundable_flag_maps_to_non_refundable()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Legacy Rate',
            'slug' => 'legacy-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'refundable' => false,
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'legacy-rate',
            'cancellation_policy' => 'non_refundable',
            'refundable' => false,
        ]);
    }

    public function test_legacy_payload_with_refundable_true_clears_a_stored_non_refundable_policy()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->nonRefundable()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => $rate->title,
            'slug' => $rate->slug,
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'refundable' => true,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        // The stored policy must be cleared, or it would keep enforcing non-refundable
        // while the row claims refundable=true.
        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
            'refundable' => true,
        ]);
    }

    public function test_update_without_cancellation_keys_keeps_the_stored_policy()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->nonRefundable()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Renamed Rate',
            'slug' => $rate->slug,
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        // A payload that says nothing about cancellation must not wipe the stored policy.
        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'title' => 'Renamed Rate',
            'cancellation_policy' => 'non_refundable',
            'refundable' => false,
        ]);
    }

    public function test_legacy_refundable_true_keeps_an_explicit_free_cancellation_policy()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->freeCancellation(7)->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => $rate->title,
            'slug' => $rate->slug,
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'refundable' => true,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(200);

        // free_cancellation is already refundable — the configured period must survive.
        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
            'refundable' => true,
        ]);
    }

    public function test_cancellation_policy_validation()
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
            'cancellation_policy' => 'partial_refund',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('cancellation_policy');
    }

    public function test_free_cancellation_period_is_required_for_free_cancellation_policy()
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
            'cancellation_policy' => 'free_cancellation',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('free_cancellation_period');
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

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);
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

    public function test_cannot_delete_rate_with_active_child_reservations()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $parentReservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
            'status' => 'confirmed',
        ]);

        ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parentReservation->id,
        ]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(422);

        $this->assertDatabaseHas('resrv_rates', ['id' => $rate->id, 'deleted_at' => null]);
    }

    public function test_can_delete_rate_when_child_reservations_are_terminal()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create(['collection' => 'pages']);

        $parentReservation = Reservation::factory()->create([
            'item_id' => $item->id(),
            'type' => 'parent',
            'status' => 'expired',
        ]);

        ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $parentReservation->id,
        ]);

        $response = $this->delete(cp_route('resrv.rate.destroy', $rate->id));
        $response->assertStatus(200);

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);
    }

    public function test_shared_rate_requires_base_rate_id()
    {
        $this->withExceptionHandling();

        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Shared No Base',
            'slug' => 'shared-no-base',
            'pricing_type' => 'relative',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'shared',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors('base_rate_id');
    }

    public function test_shared_rate_accepts_base_rate_id()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Shared With Base',
            'slug' => 'shared-with-base',
            'pricing_type' => 'relative',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);
    }

    public function test_shared_rate_accepts_independent_pricing()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Shared Independent',
            'slug' => 'shared-independent',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'shared-independent',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
        ]);
    }

    public function test_can_update_rate_slug_to_one_used_by_deleted_rate()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $deletedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'old-slug',
        ]);
        $this->delete(cp_route('resrv.rate.destroy', $deletedRate->id));

        $activeRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'current-slug',
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Updated Rate',
            'slug' => 'old-slug',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.rate.update', $activeRate->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $activeRate->id,
            'slug' => 'old-slug',
        ]);
    }

    public function test_rejects_shared_rate_as_base_rate()
    {
        $this->withExceptionHandling();
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages', 'slug' => 'base-rate']);

        $sharedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'shared-rate',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Chained Shared',
            'slug' => 'chained-shared',
            'pricing_type' => 'relative',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 5,
            'availability_type' => 'shared',
            'base_rate_id' => $sharedRate->id,
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors('base_rate_id');
    }

    public function test_rejects_relative_rate_as_base_rate()
    {
        $this->withExceptionHandling();
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages', 'slug' => 'base-rate']);

        $relativeRate = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'slug' => 'relative-rate',
            'base_rate_id' => $baseRate->id,
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Chained Relative',
            'slug' => 'chained-relative',
            'pricing_type' => 'relative',
            'base_rate_id' => $relativeRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors('base_rate_id');
    }

    public function test_independent_rate_does_not_require_base_rate_id()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Independent Rate',
            'slug' => 'independent-rate',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);
    }
}

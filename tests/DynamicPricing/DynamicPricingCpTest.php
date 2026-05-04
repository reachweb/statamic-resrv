<?php

namespace Reach\StatamicResrv\Tests\DynamicPricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Tests\TestCase;

class DynamicPricingCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_dynamic_pricings()
    {
        $dynamic = DynamicPricing::factory()->create();

        $response = $this->get(cp_route('resrv.dynamicpricing.index'));
        $response->assertStatus(200)->assertSee($dynamic->title);
    }

    public function test_can_show_cp_index_page()
    {
        $dynamic = DynamicPricing::factory()->create();

        $response = $this->get(cp_route('resrv.dynamicpricings.index'));
        $response->assertStatus(200)->assertSee('dynamic-pricing');
    }

    public function test_can_add_dynamic_pricing_for_statamic_item()
    {
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item1->id(), $item2->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'title' => $dynamic['title'],
        ]);
        $this->assertDatabaseHas('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item1->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
    }

    public function test_can_add_dynamic_pricing_for_extra()
    {
        $extra = Extra::factory()->make()->toArray();

        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['extras'] = [$extra['id']];
        $dynamic['entries'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'title' => $dynamic['title'],
        ]);
        $this->assertDatabaseHas('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $extra['id'],
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Extra',
        ]);
    }

    public function test_can_edit_dynamic_pricing_for_statamic_item()
    {
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item1->id(), $item2->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $payload = [
            'title' => '10% off for 4 days',
            'amount_type' => 'percent',
            'amount_operation' => 'decrease',
            'amount' => '10',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(60, 'day')->toIso8601String(),
            'date_include' => 'all',
            'condition_type' => 'reservation_duration',
            'condition_comparison' => '>=',
            'condition_value' => '4',
            'order' => 1,
            'entries' => [$item1->id()],
            'extras' => [],
        ];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $payload);

        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'title' => $payload['title'],
            'amount' => $payload['amount'],
        ]);
        $this->assertDatabaseHas('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item1->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
        $this->assertDatabaseMissing('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item2->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
    }

    public function test_can_add_dynamic_pricing_with_minimum_operation()
    {
        $item1 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->fixedMinimum()->make()->toArray();
        $dynamic['entries'] = [$item1->id()];
        $dynamic['extras'] = [];

        $response = $this->postJson(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'amount_operation' => 'minimum',
            'amount_type' => 'fixed',
        ]);
    }

    public function test_can_add_dynamic_pricing_with_maximum_operation()
    {
        $item1 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->fixedMaximum()->make()->toArray();
        $dynamic['entries'] = [$item1->id()];
        $dynamic['extras'] = [];

        $response = $this->postJson(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'amount_operation' => 'maximum',
            'amount_type' => 'fixed',
        ]);
    }

    public function test_rejects_percent_with_minimum_operation()
    {
        $this->withExceptionHandling();

        $item1 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['amount_operation'] = 'minimum';
        $dynamic['amount_type'] = 'percent';
        $dynamic['entries'] = [$item1->id()];
        $dynamic['extras'] = [];

        $response = $this->postJson(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount_type']);
    }

    public function test_rejects_unknown_operation()
    {
        $this->withExceptionHandling();

        $item1 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['amount_operation'] = 'subtract';
        $dynamic['entries'] = [$item1->id()];
        $dynamic['extras'] = [];

        $response = $this->postJson(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount_operation']);
    }

    public function test_can_delete_dynamic_pricing()
    {
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->create()->toArray();

        $dynamic['entries'] = [$item1->id(), $item2->id()];
        $dynamic['extras'] = [];

        $response = $this->patch(cp_route('resrv.dynamicpricing.update', $dynamic['id']), $dynamic);

        $response = $this->delete(cp_route('resrv.dynamicpricing.delete', $dynamic));

        $this->assertDatabaseMissing('resrv_dynamic_pricing', [
            'title' => $dynamic['title'],
        ]);
        $this->assertDatabaseMissing('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item1->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
        $this->assertDatabaseMissing('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item2->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
    }

    public function test_index_returns_paginated_shape()
    {
        DynamicPricing::factory()->count(3)->create();

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index'));

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'last_page', 'per_page', 'current_page']);
        $this->assertSame(3, $response->json('total'));
    }

    public function test_index_search_filters_by_title()
    {
        DynamicPricing::factory()->create(['title' => 'Summer special', 'order' => 1]);
        DynamicPricing::factory()->create(['title' => 'Winter promo', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?search=summer');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Summer special', $response->json('data.0.title'));
    }

    public function test_index_filter_by_operation()
    {
        DynamicPricing::factory()->percentIncrease()->create(['title' => 'inc', 'order' => 1]);
        DynamicPricing::factory()->percentDecrease()->create(['title' => 'dec', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?operation=increase');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('inc', $response->json('data.0.title'));
    }

    public function test_index_filter_by_condition_none()
    {
        DynamicPricing::factory()->create(['title' => 'with', 'order' => 1]);
        DynamicPricing::factory()->create([
            'title' => 'without',
            'condition_type' => null,
            'condition_comparison' => null,
            'condition_value' => null,
            'order' => 2,
        ]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?condition=none');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('without', $response->json('data.0.title'));
    }

    public function test_index_filter_by_condition_type()
    {
        DynamicPricing::factory()->create(['title' => 'duration', 'order' => 1]); // condition_type = reservation_duration
        DynamicPricing::factory()->daysToReservation()->create(['title' => 'days', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?condition=days_to_reservation');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('days', $response->json('data.0.title'));
    }

    public function test_index_filter_dates_active_always()
    {
        DynamicPricing::factory()->create(['title' => 'has-dates', 'order' => 1]);
        DynamicPricing::factory()->noDates()->create(['title' => 'always-on', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?dates_active=always');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('always-on', $response->json('data.0.title'));
    }

    public function test_index_filter_dates_active_expired()
    {
        DynamicPricing::factory()->create([
            'title' => 'past',
            'date_start' => now()->subDays(20)->toIso8601String(),
            'date_end' => now()->subDays(5)->toIso8601String(),
            'order' => 1,
        ]);
        DynamicPricing::factory()->create(['title' => 'future-active', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?dates_active=expired');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('past', $response->json('data.0.title'));
    }

    public function test_index_filter_dates_active_upcoming()
    {
        DynamicPricing::factory()->create([
            'title' => 'soon',
            'date_start' => now()->addDays(5)->toIso8601String(),
            'date_end' => now()->addDays(10)->toIso8601String(),
            'order' => 1,
        ]);
        DynamicPricing::factory()->create(['title' => 'now-active', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?dates_active=upcoming');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('soon', $response->json('data.0.title'));
    }

    public function test_index_per_page_is_respected()
    {
        DynamicPricing::factory()->count(7)->create();

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?per_page=3');

        $this->assertSame(7, $response->json('total'));
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('per_page'));
    }

    public function test_index_coupons_only_still_returns_flat_array()
    {
        DynamicPricing::factory()->create(['title' => 'no-coupon', 'order' => 1]);
        DynamicPricing::factory()->withCoupon()->create(['title' => 'has-coupon', 'order' => 2]);

        $response = $this->getJson(cp_route('resrv.dynamicpricing.index').'?coupons_only=true');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('data', $data);
        $this->assertCount(1, $data);
        $this->assertSame('has-coupon', $data[0]['title']);
    }

    public function test_order_endpoint_clamps_out_of_range_values()
    {
        $a = DynamicPricing::factory()->create(['title' => 'a', 'order' => 1]);
        $b = DynamicPricing::factory()->create(['title' => 'b', 'order' => 2]);
        $c = DynamicPricing::factory()->create(['title' => 'c', 'order' => 3]);

        // Clamp high
        $this->patch(cp_route('resrv.dynamicpricing.order'), ['id' => $a->id, 'order' => 999])
            ->assertStatus(200);
        $this->assertSame(3, DynamicPricing::find($a->id)->order);

        // Clamp low
        $this->patch(cp_route('resrv.dynamicpricing.order'), ['id' => $c->id, 'order' => 0])
            ->assertStatus(200);
        $this->assertSame(1, DynamicPricing::find($c->id)->order);
    }

    public function test_neighbour_order_round_trip_across_pages()
    {
        // Five pricings in order 1..5. Per-page = 2 → page 2 is items 3,4.
        $items = collect(range(1, 5))->map(fn ($i) => DynamicPricing::factory()->create([
            'title' => "p{$i}",
            'order' => $i,
        ]));

        // Simulate dragging item at order=4 above item at order=3 on page 2.
        // Frontend sends neighbour's order (3) as the target.
        $itemAt4 = $items[3];
        $this->patch(cp_route('resrv.dynamicpricing.order'), ['id' => $itemAt4->id, 'order' => 3])
            ->assertStatus(200);

        // After: p4 should have order=3, p3 should have order=4.
        $this->assertSame(3, DynamicPricing::where('title', 'p4')->value('order'));
        $this->assertSame(4, DynamicPricing::where('title', 'p3')->value('order'));
    }
}

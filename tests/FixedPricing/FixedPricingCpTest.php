<?php

namespace Reach\StatamicResrv\Tests\FixedPricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class FixedPricingCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_add_fixed_pricing_for_statamic_item()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);

        $response = $this->get(cp_route('resrv.fixedpricing.index', $item->id()));
        $response->assertStatus(200)->assertSee($item->id());
    }

    public function test_fixed_pricing_returns_empty_array_not_found()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);

        $response = $this->get(cp_route('resrv.fixedpricing.index', 'test'));
        $response->assertStatus(200)->assertSee('[]');
    }

    public function test_fixed_pricing_update_method()
    {
        $item = $this->makeStatamicItem();
        Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 105.25,
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'price' => 105.25,
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 120.25,
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'price' => 120.25,
        ]);
    }

    public function test_fixed_pricing_can_delete()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $fixed_pricing = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);

        $response = $this->delete(cp_route('resrv.fixedpricing.delete'), $fixed_pricing->toArray());
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_fixed_pricing', [
            'id' => $fixed_pricing->id,
        ]);
    }

    public function test_index_deduplicates_across_rates()
    {
        $item = $this->makeStatamicItem();
        $rate1 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'standard']);
        $rate2 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'non-refundable']);

        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate1->id,
            'days' => 3,
            'price' => '100.00',
        ]);
        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate2->id,
            'days' => 3,
            'price' => '100.00',
        ]);

        $response = $this->get(cp_route('resrv.fixedpricing.index', $item->id()));
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertCount(1, $data);
    }

    public function test_update_syncs_across_independent_rates_only()
    {
        $item = $this->makeStatamicItem();
        $rate1 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'standard']);
        $rate2 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'non-refundable']);
        $relativeRate = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $rate1->id,
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 200.00,
        ];

        $this->post(cp_route('resrv.fixedpricing.update'), $payload)->assertStatus(200);

        // Independent rates get fixed pricing
        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'days' => '4',
            'rate_id' => $rate1->id,
            'price' => 200.00,
        ]);
        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'days' => '4',
            'rate_id' => $rate2->id,
            'price' => 200.00,
        ]);

        // Relative rate should NOT get a fixed pricing row
        $this->assertDatabaseMissing('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'days' => '4',
            'rate_id' => $relativeRate->id,
        ]);

        // Update price — both independent rates updated, relative still excluded
        $payload['price'] = 250.00;
        $this->post(cp_route('resrv.fixedpricing.update'), $payload)->assertStatus(200);

        $rows = FixedPricing::where('statamic_id', $item->id())->where('days', '4')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->price->format() === '250.00'));
    }

    public function test_delete_removes_matching_price_across_rates()
    {
        $item = $this->makeStatamicItem();
        $rate1 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'standard']);
        $rate2 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'non-refundable']);

        $fp1 = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate1->id,
            'days' => 3,
            'price' => '100.00',
        ]);
        $fp2 = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate2->id,
            'days' => 3,
            'price' => '100.00',
        ]);

        $this->delete(cp_route('resrv.fixedpricing.delete'), ['id' => $fp1->id])->assertStatus(200);

        $this->assertDatabaseMissing('resrv_fixed_pricing', ['id' => $fp1->id]);
        $this->assertDatabaseMissing('resrv_fixed_pricing', ['id' => $fp2->id]);
    }

    public function test_delete_preserves_different_price_for_same_days()
    {
        $item = $this->makeStatamicItem();
        $rate1 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'standard']);
        $rate2 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'premium']);

        $fp1 = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate1->id,
            'days' => 3,
            'price' => '100.00',
        ]);
        $fp2 = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate2->id,
            'days' => 3,
            'price' => '150.00',
        ]);

        $this->delete(cp_route('resrv.fixedpricing.delete'), ['id' => $fp1->id])->assertStatus(200);

        $this->assertDatabaseMissing('resrv_fixed_pricing', ['id' => $fp1->id]);
        $this->assertDatabaseHas('resrv_fixed_pricing', ['id' => $fp2->id]);
    }

    public function test_edit_existing_row_updates_synced_duplicates_only()
    {
        $item = $this->makeStatamicItem();
        $rate1 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'standard']);
        $rate2 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'non-refundable']);
        $rate3 = Rate::factory()->create(['collection' => 'pages', 'slug' => 'premium']);

        // Two rates share same price (synced), one has a distinct override
        $fp1 = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate1->id,
            'days' => 3,
            'price' => '100.00',
        ]);
        FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate2->id,
            'days' => 3,
            'price' => '100.00',
        ]);
        $fpOverride = FixedPricing::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate3->id,
            'days' => 3,
            'price' => '150.00',
        ]);

        // Edit one of the synced rows — should update both synced rows, not the override
        $this->post(cp_route('resrv.fixedpricing.update'), [
            'id' => $fp1->id,
            'statamic_id' => $item->id(),
            'days' => '3',
            'price' => 120.00,
        ])->assertStatus(200);

        // Both synced rows updated
        $this->assertDatabaseHas('resrv_fixed_pricing', ['id' => $fp1->id, 'price' => 120.00]);
        $this->assertDatabaseHas('resrv_fixed_pricing', ['rate_id' => $rate2->id, 'days' => '3', 'price' => 120.00]);

        // Override untouched
        $this->assertDatabaseHas('resrv_fixed_pricing', ['id' => $fpOverride->id, 'price' => 150.00]);
    }

    public function test_update_creates_default_rate_when_none_exists()
    {
        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'days' => '5',
            'price' => 150.00,
        ];

        $this->post(cp_route('resrv.fixedpricing.update'), $payload)->assertStatus(200);

        $row = FixedPricing::where('statamic_id', $item->id())->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->rate_id);

        $rate = Rate::find($row->rate_id);
        $this->assertEquals('pages', $rate->collection);
        $this->assertEquals('default', $rate->slug);
    }
}

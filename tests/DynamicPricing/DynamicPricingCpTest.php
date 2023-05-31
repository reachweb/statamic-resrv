<?php

namespace Reach\StatamicResrv\Tests\DynamicPricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Tests\TestCase;

class DynamicPricingCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
        DynamicPricing::factory()->create();

        $response = $this->get(cp_route('resrv.dynamicpricings.index'));
        $response->assertStatus(200);
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
            'amount_operation' => 'subtract',
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

    public function test_can_delete_dynamic_pricing()
    {
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item1->id(), $item2->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

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
}

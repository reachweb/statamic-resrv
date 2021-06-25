<?php

namespace Reach\StatamicResrv\Tests\DynamicPricing;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\DynamicPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class DynamicPricingCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_add_dynamic_pricing_for_statamic_item()
    {
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        
        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item1->id(), $item2->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create.entries'), $dynamic);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_dynamic_pricing', [
            'title' => $dynamic['title']
        ]);
        $this->assertDatabaseHas('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item1->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
        
    }
     
}

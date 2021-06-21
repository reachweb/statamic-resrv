<?php

namespace Reach\StatamicResrv\Tests\FixedPricing;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\FixedPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class FixedPricingCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_add_fixed_pricing_for_statamic_item()
    {
        $item = $this->makeStatamicItem();
        
        FixedPricing::factory()            
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->get(cp_route('resrv.fixedpricing.index', $item->id()));
        $response->assertStatus(200)->assertSee($item->id());
        
    }
    
    public function test_fixed_pricing_returns_empty_array_not_found()
    {
        $item = $this->makeStatamicItem();
        
        FixedPricing::factory()
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->get(cp_route('resrv.fixedpricing.index', 'test'));
        $response->assertSee('[]');        
        
    }

    public function test_fixed_pricing_update_method()
    {
        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'days' => '4',            
            'price' => 105.25
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'price' => 105.25
        ]);

        $payload = [
            'statamic_id' => $item->id(),
            'days' => '4',            
            'price' => 120.25
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_fixed_pricing', [
            'statamic_id' => $item->id(),
            'price' => 120.25
        ]);
    }

    public function test_fixed_pricing_can_delete()
    {
        $item = $this->makeStatamicItem();
        
        $fixed_pricing = FixedPricing::factory()
            ->create(
                ['statamic_id' => $item->id()]
            );

        $response = $this->delete(cp_route('resrv.fixedpricing.delete'), $fixed_pricing->toArray());
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_fixed_pricing', [
            'id' => $fixed_pricing->id
        ]);
    }
     
}

<?php

namespace Reach\StatamicResrv\Tests\DynamicPricing;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\DynamicPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class DynamicPricingFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();        
    }
    
    public function test_dynamic_pricing_changes_availability_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        // Search for 4 days without dynamic pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);                
        
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');
        
        // We should get 121.10 for 20% percent increase
        $dynamic = DynamicPricing::factory()->percentIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('121.10');
        
        // We should get 80 for 20.92 fixed decrease
        $dynamic = DynamicPricing::factory()->fixedDecrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.00');
        
        // We should get 111.00 for 10.08 fixed increase
        $dynamic = DynamicPricing::factory()->fixedIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('111.00');

        // Reset the dynamic pricing entry
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Check that it doesn't work if date is outside range
        $searchPayload = [
            'date_start' => today()->setHour(12)->add(7, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(11, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // But it works for "start" date condition
        $dynamic = DynamicPricing::factory()->dateStart()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        // And "most" date condition
        $dynamic = DynamicPricing::factory()->dateMost()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        // Even further
        $searchPayload = [
            'date_start' => today()->setHour(12)->add(11, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(15, 'day')->toISOString(),
        ];

        // Shouldn't work for "start" date condition
        $dynamic = DynamicPricing::factory()->dateStart()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // And "most" date condition
        $dynamic = DynamicPricing::factory()->dateMost()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // Reset the search again
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // Let's check reservation_duration condition
        $dynamic = DynamicPricing::factory()->conditionExtraDuration()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Shouldn't work for 4 days
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(8, 'day')->toISOString(),
        ];

        // Should work for 8 days
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('161.47');

        // Reset the search again
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // Let's check reservation_price condition
        $dynamic = DynamicPricing::factory()->conditionPriceOver()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        
        // Should work for 101.92 original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        // Let's check reservation_price reverse condition
        $dynamic = DynamicPricing::factory()->conditionPriceUnder()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        
        // Shouldn't work for 101.92 original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

                
    }

    public function test_multiple_dynamic_pricing_on_availability_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        // Search for 4 days without dynamic pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        // Lets add a 10.08 fixed increase
        $dynamic = DynamicPricing::factory()->fixedIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('90.82');
        
        // Lets add a 20% increase
        $dynamic = DynamicPricing::factory()->percentIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('108.98');
    }

    public function test_dynamic_pricing_changes_single_item_availability_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        // Search for 4 days without dynamic pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('100.92');

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

    }
    
    public function test_dynamic_pricing_applies_to_fixed_pricing()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $fixedPricingPayload = [
            'statamic_id' => $item->id(),
            'days' => '4',            
            'price' => 90
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $fixedPricingPayload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        // Search for 4 days without dynamic pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('90');

        // We should get 72 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('72');

    }

    public function test_dynamic_pricing_changes_reservation_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        
        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(10, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2
        ];        
        
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');
        
        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;

        // Confirm that booking works for 4 days
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $price
        ];
        
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment
        ]);
        
    } 

}

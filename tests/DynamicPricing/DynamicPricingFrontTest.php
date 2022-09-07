<?php

namespace Reach\StatamicResrv\Tests\DynamicPricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Tests\TestCase;

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
            'available' => 2,
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

        Cache::flush();

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);
        $response->assertStatus(200);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74')->assertSee('100.92');

        Cache::flush();

        // We should get 121.10 for 20% percent increase
        $dynamic = DynamicPricing::factory()->percentIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('121.10')->assertSee('100.92');

        Cache::flush();

        // We should get 80 for 20.92 fixed decrease
        $dynamic = DynamicPricing::factory()->fixedDecrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.00')->assertSee('100.92');

        Cache::flush();

        // We should get 111.00 for 10.08 fixed increase
        $dynamic = DynamicPricing::factory()->fixedIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('111.00')->assertSee('100.92');

        Cache::flush();

        // Reset the dynamic pricing entry
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Check that it doesn't work if date is outside range
        $searchPayload = [
            'date_start' => today()->setHour(12)->add(7, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(11, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        Cache::flush();

        // But it works for "start" date condition
        $dynamic = DynamicPricing::factory()->dateStart()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74')->assertSee('100.92');

        Cache::flush();

        // And "most" date condition
        $dynamic = DynamicPricing::factory()->dateMost()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74')->assertSee('100.92');

        Cache::flush();

        // Even further
        $searchPayload = [
            'date_start' => today()->setHour(12)->add(11, 'day')->toISOString(),
            'date_end' => today()->setHour(12)->add(15, 'day')->toISOString(),
        ];

        // Shouldn't work for "start" date condition
        $dynamic = DynamicPricing::factory()->dateStart()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // And "most" date condition
        $dynamic = DynamicPricing::factory()->dateMost()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        Cache::flush();

        // Reset the search again
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // Let's check reservation_duration condition
        $dynamic = DynamicPricing::factory()->conditionExtraDuration()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

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

        Cache::flush();

        // Reset the search again
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // Let's check reservation_price condition
        $dynamic = DynamicPricing::factory()->conditionPriceOver()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Should work for 101.92 original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        Cache::flush();

        // Let's check reservation_price reverse condition
        $dynamic = DynamicPricing::factory()->conditionPriceUnder()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Shouldn't work for 101.92 original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        Cache::flush();

        // Let's check without dates
        $dynamic = DynamicPricing::factory()->noDates()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->patch(cp_route('resrv.dynamicpricing.update', 1), $dynamic);

        // Should still work original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        Cache::flush();
    }

    public function test_dynamic_pricing_changes_availability_prices_in_show_route()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2,
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

        Cache::flush();

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();

        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74')->assertSee('100.92');

        Cache::flush();
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
            'available' => 2,
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
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('80.74');

        Cache::flush();

        // Lets add a 10.08 fixed increase
        $dynamic = DynamicPricing::factory()->fixedIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('90.82');

        Cache::flush();

        // Lets add a 20% increase
        $dynamic = DynamicPricing::factory()->percentIncrease()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('108.98');

        Cache::flush();
    }

    public function test_dynamic_pricing_applies_by_ordering()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(10, 'day')->toISOString(),
            'price' => 50,
            'available' => 2,
        ];

        $this->post(cp_route('resrv.availability.update'), $payload);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        // Lets add a 50% decrease
        $dynamic = DynamicPricing::factory()->percentDecrease()->make([
            'title' => 'Take 50% off',
            'amount' => '50',
        ])->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        // Lets add 50
        $dynamic = DynamicPricing::factory()->fixedIncrease()->make([
            'title' => 'Add 50',
            'amount' => '50',
        ])->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        // Reorder them
        $this->patch(cp_route('resrv.dynamicpricing.order'), [
            'id' => 1,
            'order' => 2,
        ]);
        $this->patch(cp_route('resrv.dynamicpricing.order'), [
            'id' => 2,
            'order' => 1,
        ]);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('125');

        Cache::flush();
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
            'available' => 2,
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

        Cache::flush();

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        Cache::flush();
    }

    public function test_dynamic_pricing_with_expiration_date()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->add(10, 'day')->setHour(12)->toISOString(),
            'date_end' => today()->add(14, 'day')->setHour(12)->toISOString(),
        ];

        // We should get 80.74 for 20% percent decrease when not expired
        $dynamic = DynamicPricing::factory()->expires()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        Cache::flush();

        // Testing for time - after 5 days and 5 hours it should not have expired
        $this->travelBack();
        $this->travelTo(today()->add(5, 'day')->setHour(5));
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        // Testing for time - after 5 days and 11 hours it should have expired so we shoudn't see it
        $this->travelBack();
        $this->travelTo(today()->add(5, 'day')->setHour(11));
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertDontSee('80.74')->assertSee('100.92');

        // After 6 days it should have expired so we shoudn't see it
        $this->travelBack();
        $this->travelTo(today()->add(6, 'day')->setHour(11));
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertDontSee('80.74')->assertSee('100.92');

        Cache::flush();
    }

    public function test_dynamic_pricing_with_coupon()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 2,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $dynamic = DynamicPricing::factory()->withCoupon()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        // We should not get the discount when the coupon is not in the session
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertDontSee('80.74')->assertSee('100.92');

        Cache::flush();        
        
        // We should get the discount when the coupon is in the session
        $response = $this->withSession(['resrv_coupon' => '20OFF'])->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        Cache::flush();

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
            'available' => 2,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $fixedPricingPayload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 90,
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

        Cache::flush();

        // We should get 72 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('72');

        Cache::flush();
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
            'available' => 2,
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

        Cache::flush();

        // We should get 80.74 for 20% percent decrease
        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('80.74');

        Cache::flush();

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;

        // Confirm that booking works for 4 days
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $price,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
    }

    public function test_dynamic_pricing_changes_extra_price()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $this->signInAdmin();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $extra = Extra::factory()->create();

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];

        $dynamic = DynamicPricing::factory()->extra()->make()->toArray();
        $dynamic['extras'] = [$extra->id];
        $dynamic['entries'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        // Decrease the price here
        $total = json_decode($response->content())->data->price + (json_decode($response->content())->request->days * 2.65);

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]],
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);
    }

    public function test_dynamic_pricing_works_with_multiple_items()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 3,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
            'quantity' => 3,
        ];

        $dynamic = DynamicPricing::factory()->make()->toArray();
        $dynamic['entries'] = [$item->id()];
        $dynamic['extras'] = [];

        $response = $this->post(cp_route('resrv.dynamicpricing.create'), $dynamic);

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('242.22');
    }
}

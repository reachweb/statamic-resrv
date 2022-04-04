<?php

namespace Reach\StatamicResrv\Tests\FixedPricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Tests\TestCase;

class FixedPricingFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_fixed_pricing_changes_availability_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(6, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 3,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        // Search for 4 days without fixed pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('100.92');

        // Add fixed pricing
        $fixedPricingPayload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 90,
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $fixedPricingPayload);
        $response->assertStatus(200);

        // Search for 4 days and get the fixed price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('90');

        // Check individual pricing search
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('90')->assertSee('message":{"status":1}}', false);

        // Check for extra days
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(6, 'day')->toISOString(),
        ];
        // Make sure we get the original price
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('151.38');

        // Add extra days fixed pricing
        $fixedPricingPayloadExtra = [
            'statamic_id' => $item->id(),
            'days' => '0',
            'price' => 20,
        ];
        $response = $this->post(cp_route('resrv.fixedpricing.update'), $fixedPricingPayloadExtra);
        $response->assertStatus(200);

        // Check the extra day fixed pricing
        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(6, 'day')->toISOString(),
        ];
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('130');

        // Check it works for multiple items
        $searchPayload['quantity'] = 3;
        $response = $this->post(route('resrv.availability.index'), $searchPayload);
        $response->assertStatus(200)->assertSee($item->id())->assertSee('390');
    }

    public function test_fixed_pricing_changes_reservation_prices()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(6, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 3,
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

        $fixedPricingPayload = [
            'statamic_id' => $item->id(),
            'days' => '4',
            'price' => 90,
        ];

        $response = $this->post(cp_route('resrv.fixedpricing.update'), $fixedPricingPayload);
        $response->assertStatus(200);

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('90');

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

        // Confirm that it works for extra days fixed pricing

        // Add extra days
        $fixedPricingPayloadExtra = [
            'statamic_id' => $item->id(),
            'days' => '0',
            'price' => 20,
        ];
        $response = $this->post(cp_route('resrv.fixedpricing.update'), $fixedPricingPayloadExtra);
        $response->assertStatus(200);

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(6, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('130');

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(6, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $price,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
    }

    public function test_fixed_pricing_changes_reservation_prices_for_multiple_items()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(6, 'day')->toISOString(),
            'price' => 25.23,
            'available' => 3,
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

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
            'quantity' => 3,
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('270');

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;

        // Confirm that booking works for 4 days
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
            'quantity' => 3,
            'payment' => $payment,
            'price' => $price,
            'total' => $price,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
            'quantity' => 3,
        ]);
    }
}

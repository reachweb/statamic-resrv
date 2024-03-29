<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Tests\TestCase;

class UtilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_availability_search_gets_saved_in_session()
    {
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'quantity' => 2,
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);

        $response->assertSessionHas([
            'resrv_search' => $searchPayload,
        ]);

        $this->assertTrue(true);
    }

    public function test_get_saved_availability_via_endpoint()
    {
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'quantity' => 2,
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);

        $response->assertSessionHas([
            'resrv_search' => $searchPayload,
        ]);

        $response = $this->get(route('resrv.utility.getSavedSearch'));

        $response->assertStatus(200)->assertJson($searchPayload);
    }

    public function test_availability_search_does_not_get_saved_in_session()
    {
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'quantity' => 2,
            'forget' => true,
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);

        $response->assertSessionMissing('resrv_search');

        $this->assertTrue(true);
    }

    public function test_token_method()
    {
        $response = $this->get(route('resrv.utility.token'));
        $response->assertStatus(200)->assertSee(csrf_token());
    }

    public function test_can_add_coupon_if_exists()
    {
        DynamicPricing::factory()->withCoupon()->create();
        $response = $this->post(route('resrv.utility.addCoupon'), ['coupon' => '20OFF']);
        $response->assertStatus(200)->assertSessionHas(['resrv_coupon' => '20OFF']);
    }

    public function test_cannot_add_coupon_if_it_doesnt_exist()
    {
        $response = $this->post(route('resrv.utility.addCoupon'), ['coupon' => '20OFF']);
        $response->assertStatus(412);
        $this->assertTrue(true);
    }

    public function test_can_remove_coupon()
    {
        DynamicPricing::factory()->withCoupon()->create();
        $response = $this->post(route('resrv.utility.addCoupon'), ['coupon' => '20OFF']);
        $response->assertStatus(200)->assertSessionHas(['resrv_coupon' => '20OFF']);

        $response = $this->delete(route('resrv.utility.removeCoupon'));
        $response->assertStatus(200)->assertSessionMissing(['resrv_coupon' => '20OFF']);
    }

    public function test_can_get_coupon_in_session()
    {
        DynamicPricing::factory()->withCoupon()->create();
        $response = $this->post(route('resrv.utility.addCoupon'), ['coupon' => '20OFF']);
        $response->assertStatus(200)->assertSessionHas(['resrv_coupon' => '20OFF']);

        $response = $this->get(route('resrv.utility.getCoupon'));
        $response->assertStatus(200)->assertSee(['coupon' => '20OFF']);
    }

    public function test_availability_search_can_be_set_by_url()
    {
        $item = $this->makeStatamicItem();
        $this->withStandardFakeViews();

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
        ];

        // Test normal
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables())->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end'], 'GET'),
            fn () => new \Symfony\Component\HttpFoundation\Response()
        );

        // Again to enable assertion (probably there is a better way to do this)
        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end']);

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);

        // Test normal with duration
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables())->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something', 'GET'),
            fn () => new \Symfony\Component\HttpFoundation\Response()
        );

        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something');

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->addDays(5)->toDateString(),
            'advanced' => 'something',
        ];

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);
    }
}

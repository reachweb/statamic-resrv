<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Config;

class ReservationFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();        
    }

    public function test_reservation_confirm_method_success()
    {
        //$this->withExceptionHandling();
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

        $location = Location::factory()->create(); 

        $extra = Extra::factory()->create();

        $addExtraToEntry = [
            'id' => $extra->id
        ];
        
        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);

        $searchPayload = [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        
        $checkoutRequest = [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]], 
            'location_start' => 1, 
            'location_end' => 1,
            'total' => 620
        ];

        Config::set('resrv-config.enable_locations', true);
      
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee(1);
        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1
        ]);

                
    }    
    
    public function test_reservation_confirm_method_fail()
    {
        //$this->withExceptionHandling();
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

        $location = Location::factory()->create(); 

        $extra = Extra::factory()->create();

        $addExtraToEntry = [
            'id' => $extra->id
        ];
        
        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);

        $searchPayload = [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        
        $checkoutRequest = [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]], 
            'total' => 333
        ];
        
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee('{"error":"405"}', false);
        $this->assertDatabaseMissing('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1
        ]);

                
    }


}

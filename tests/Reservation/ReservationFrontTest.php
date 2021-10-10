<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ReservationFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Config::set('resrv-config.stripe_key', 'sk_test_51ImFMAD6a3Agl4C6BCvvOsnR8u5mzk8GUbjg2iInyX8qRqTL2JviqnRUfRw8T5Uq4WIv5IosSFmq22DS8h0JM35200DhjC2wqS');    
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
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = json_decode($response->content())->data->price + (json_decode($response->content())->request->days * $extra->price->format()) + ($location->extra_charge->format() * 2);
        
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]], 
            'location_start' => 1, 
            'location_end' => 1,
            'total' => $total
        ];

        Config::set('resrv-config.enable_locations', true);
      
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1
        ]);

        Config::set('resrv-config.minutes_to_hold', 10);
        // Check that availability gets decreased here
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
        ]);
        
        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();

        // Call availability to run the jobs
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $item->id(),
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 2,
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
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]], 
            'total' => 333
        ];
        
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(412);
        $this->assertDatabaseMissing('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1
        ]);
                
    }

    public function test_reservation_customer_checkout_form_exists()
    {
        $item = $this->makeStatamicItem();
        $location = Location::factory()->create(); 

        $reservation = Reservation::factory()
            ->create([
            'item_id' => $item->id(),
            'location_start' => $location->id,
            'location_end' => $location->id,
        ]);

        $response = $this->get(route('resrv.reservation.checkoutForm', $reservation->id));
        $response->assertStatus(200)->assertSee('input_type');
    }
    
    public function test_reservation_customer_checkout_form_submit()
    {

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
        ])->create();
        
        $customerData = [
            'first_name' => 'Test',
            'last_name' => 'Testing',
            'email' => 'test@test.com',
            'repeat_email' => 'test@test.com',            
        ];

        $response = $this->post(route('resrv.reservation.checkoutFormSubmit', $reservation->id), $customerData);
        $response->assertStatus(200)->assertSee('Test');
        $this->assertDatabaseHas('resrv_reservations', [
            'customer->first_name' => 'Test'
        ]);
    }
    
    public function test_reservation_customer_checkout_form_submit_error()
    {
        $this->withExceptionHandling();
        $reservation = Reservation::factory()->create();

        $customerData = [
            'first_name' => 'Test',
            'last_name' => 'Testing',
            'email' => 'test@test.com',
            'repeat_email' => 'test@test.co',            
        ];

        $response = $this->post(route('resrv.reservation.checkoutFormSubmit', $reservation->id), $customerData);
        $response->assertSessionHasErrors(['repeat_email']);
    }

    public function test_reservation_confirm_checkout_method()
    {
        //$this->withExceptionHandling();
        $item = $this->makeStatamicItem();
        $location = Location::factory()->create(); 
        Config::set('resrv-config.enable_locations', true);
        Config::set('resrv-config.admin_email', 'someone@test.com,someonelse@example.com');

        $reservation = Reservation::factory([
            'customer' => ['email' => 'test@test.com'],
            'item_id' => $item->id(),
            'location_start' => $location->id,
            'location_end' => $location->id,
        ])->create();
      
        Mail::fake();

        $response = $this->post(route('resrv.reservation.checkoutConfirm', $reservation->id));
        $response->assertStatus(200)->assertSee($reservation->id);
        Mail::assertSent(ReservationConfirmed::class);
        Mail::assertSent(ReservationMade::class);
    }
    
    public function test_reservation_email_render()
    {
        //$this->withExceptionHandling();
        $item = $this->makeStatamicItem();
        $location = Location::factory()->create();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->notRequired()
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();  
        Config::set('resrv-config.enable_locations', true);
        Config::set('resrv-config.admin_email', 'someone@test.com,someonelse@example.com');

        $reservation = Reservation::factory([
            'customer' => ['email' => 'test@test.com'],
            'item_id' => $item->id(),
            'location_start' => $location->id,
            'location_end' => $location->id,
        ])->create();

        $reservation->options()->attach($option->id, ['value' => 1]);
    
        $mail = new ReservationConfirmed($reservation);
        $html = $mail->render();
        
        $mailMade = new ReservationMade($reservation);
        $htmlMade = $mailMade->render();

        $this->assertStringContainsString($location->name, $html);
        $this->assertStringContainsString($location->name, $htmlMade);

    }

    public function test_reservation_confirm_method_success_with_option()
    {
        $this->withExceptionHandling();
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

        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);


        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = json_decode($response->content())->data->price + (json_decode($response->content())->request->days * $option->values[0]->price->format());
       
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'options' => [$option->id => ['value' => 1]],
            'total' => $total
        ];
      
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option->id,
            'value' => $option->values[0]->id
        ]);
                     
    }    

    public function test_reservation_confirm_method_with_required_options()
    {
        $this->withExceptionHandling();
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

        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->notRequired()
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();  
            
        $option2 = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();  
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);


        $days = json_decode($response->content())->request->days;
        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = json_decode($response->content())->data->price + ($days * $option->values[0]->price->format());
       
        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'options' => [$option->id => ['value' => 1]],
            'total' => $total
        ];
      
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(412);

        $checkoutRequest['options'] = [$option2->id => ['value' => $option2->values[2]->id], $option->id => ['value' => $option->values[0]->id]];
        $checkoutRequest['total'] = $total + ($days * $option2->values[2]->price->format());

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option->id,
            'value' => $option->values[0]->id
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option2->id,
            'value' => $option2->values[2]->id
        ]);
                     
    }    

    public function test_reservation_with_quantity_more_than_one()
    {
        //$this->withExceptionHandling();
        $item = $this->makeStatamicItem();
        $this->signInAdmin();
        Config::set('resrv-config.enable_locations', true);

        Availability::factory()
            ->state([
                'available' => 5,
            ])
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $location = Location::factory()->create(); 

        $extra = Extra::factory()->create();

        $addExtraToEntry = [
            'id' => $extra->id
        ];
   
        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);
        
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'quantity' => 2,
        ];
        
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);        
        $response->assertStatus(200)->assertSee('600')->assertSee('message":{"status":1}}', false);

        $days = json_decode($response->content())->request->days;
        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = $price +
                (2 * ($days * $option->values[0]->price->format())) +
                (2 * ($location->extra_charge->format() * 2)) +
                (2 * ($days * $extra->price->format()));

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'quantity' => 2,
            'payment' => $payment,
            'price' => $price,
            'options' => [$option->id => ['value' => 1]],
            'extras' => [$extra->id => ['quantity' => 1]],
            'location_start' => $location->id,
            'location_end' => $location->id,
            'total' => $total
        ];
      
    
        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
            'quantity' => 2
        ]);
        
        Config::set('resrv-config.minutes_to_hold', 10);
        // Check that availability gets decreased here
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 3,
        ]);
        
        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();

        // Call availability to run the jobs
        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $item->id(),
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 5,
        ]);                
    }    

}

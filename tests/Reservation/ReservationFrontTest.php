<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Config::set('resrv-config.stripe_secret_key', 'sk_test_some_key');
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
            'total' => $total,
        ];

        Config::set('resrv-config.enable_locations', true);

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1,
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

    public function test_reservation_start_method_without_json()
    {
        $this->withStandardFakeViews();
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

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];

        $this->travelTo(today()->setHour(11));

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $price,
            'statamic_id' => $item->id(),
        ];

        $this->viewShouldReturnRendered('statamic-resrv::checkout.checkout_start', 'Test');
        $response = $this->post(route('resrv.reservation.start'), $checkoutRequest);

        $response->assertStatus(200)
            ->assertSessionHas('resrv_reservation', 1)
            ->assertSee('Test');

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
    }

    public function test_update_reservation_update_method()
    {
        $this->withStandardFakeViews();
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

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);

        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];

        $this->travelTo(today()->setHour(10));

        $availabilityResponse = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $availabilityResponse->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($availabilityResponse->content())->data->payment;
        $price = json_decode($availabilityResponse->content())->data->price;

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $price,
            'statamic_id' => $item->id(),
        ];

        $this->viewShouldReturnRendered('statamic-resrv::checkout.checkout_start', 'Test');
        $response = $this->post(route('resrv.reservation.start'), $checkoutRequest);

        $days = json_decode($availabilityResponse->content())->request->days;
        $total = $price + ($days * $extra->price->format()) + ($days * $option->values[0]->price->format());

        $checkoutRequest = [
            'payment' => $payment,
            'price' => $price,
            'total' => $total,
            'options' => [$option->id => ['value' => 1]],
            'extras' => [$extra->id => ['quantity' => 1]],
        ];

        $response = $this->patch(route('resrv.reservation.update', 1), $checkoutRequest);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'price' => $total,
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option->id,
            'value' => $option->values[0]->id,
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

        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

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
            'total' => 333,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(412);
        $this->assertDatabaseMissing('resrv_reservations', [
            'payment' => $payment,
        ]);
        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1,
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
            ]
        );

        $response = $this->get(route('resrv.reservation.checkoutForm', $reservation->item_id));
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
            'customer->first_name' => 'Test',
        ]);
    }

    public function test_reservation_completed_method()
    {
        $this->withStandardFakeViews();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'customer' => [
                'first_name' => 'Test',
                'last_name' => 'Testing',
                'email' => 'test@test.com',
                'repeat_email' => 'test@test.com',
            ],
        ])->create();

        $responseData = [
            'id' => $reservation->id,
        ];

        $this->viewShouldReturnRendered('statamic-resrv::checkout.checkout_completed', 'Test');

        Mail::fake();

        $response = $this->post(route('resrv.reservation.checkoutCompleted'), $responseData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_reservation_failed_method()
    {
        $this->withStandardFakeViews();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'customer' => [
                'first_name' => 'Test',
                'last_name' => 'Testing',
                'email' => 'test@test.com',
                'repeat_email' => 'test@test.com',
            ],
        ])->create();

        $responseData = [
            'id' => $reservation->id,
        ];

        $this->viewShouldReturnRendered('statamic-resrv::checkout.checkout_completed', 'Test');

        Mail::fake();

        $response = $this->post(route('resrv.reservation.checkoutCompleted'), $responseData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
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
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option->id,
            'value' => $option->values[0]->id,
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
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(412);

        $checkoutRequest['options'] = [$option2->id => ['value' => $option2->values[2]->id], $option->id => ['value' => $option->values[0]->id]];
        $checkoutRequest['total'] = $total + ($days * $option2->values[2]->price->format());

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option->id,
            'value' => $option->values[0]->id,
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => 1,
            'option_id' => $option2->id,
            'value' => $option2->values[2]->id,
        ]);
    }

    public function test_reservation_confirm_method_with_required_extras()
    {
        $this->withExceptionHandling();
        $item = $this->makeStatamicItem();
        $this->signInAdmin();

        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()->add(20, 'day')],
                ['date' => today()->add(21, 'day')],
                ['date' => today()->add(22, 'day')],
                ['date' => today()->add(23, 'day')],
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $extra = Extra::factory()->create();
        $extra2 = Extra::factory()->fixed()->create();
        $extra3 = Extra::factory()->fixed()->create([
            'id' => 3,
            'slug' => 'this-is-another-slug',
        ]);

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);

        $addExtra2ToEntry = [
            'id' => $extra2->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtra2ToEntry);

        $addExtra3ToEntry = [
            'id' => $extra3->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtra3ToEntry);

        ExtraCondition::factory()->requiredReservationTimeAndShow()->create([
            'extra_id' => $extra->id,
        ]);

        ExtraCondition::factory()->requiredAlways()->create([
            'extra_id' => $extra2->id,
        ]);

        ExtraCondition::factory()->requiredExtraSelected()->create([
            'extra_id' => $extra3->id,
        ]);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->add(20, 'day')->setHour(22)->toISOString(),
            'date_end' => today()->add(22, 'day')->setHour(12)->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $days = json_decode($response->content())->request->days;
        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = json_decode($response->content())->data->price;

        $checkoutRequest = [
            'date_start' => today()->add(20, 'day')->setHour(22)->toISOString(),
            'date_end' => today()->add(22, 'day')->setHour(12)->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(412);

        $checkoutRequest['extras'] = [$extra->id => ['quantity' => 1], $extra2->id => ['quantity' => 1]];
        $checkoutRequest['total'] = $total + ($days * $extra->price->format()) + $extra2->price->format();

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(412);

        $checkoutRequest['extras'] = [$extra->id => ['quantity' => 1], $extra2->id => ['quantity' => 1], $extra3->id => ['quantity' => 1]];
        $checkoutRequest['total'] = $total + ($days * $extra->price->format()) + $extra2->price->format() + $extra3->price->format();

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $total,
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
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
            'quantity' => 2,
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

    public function test_reservation_confirm_method_success_with_advanced_availability()
    {
        $this->withExceptionHandling();
        $item = $this->makeStatamicItem();
        $this->signInAdmin();

        AdvancedAvailability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something',
        ];

        $response = $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = json_decode($response->content())->data->price;

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'advanced' => 'something',
            'payment' => $payment,
            'price' => $price,
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something',
        ]);
    }

    public function test_reservation_confirm_method_with_modifier_extras()
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

        $extra = Extra::factory()->relative()->create();

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('300')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $extra_price = ($extra->price->multiply($price)->format()) * 2;
        $total = json_decode($response->content())->data->price + $extra_price;

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 2]],
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);

        $response->assertStatus(200)->assertSee(1)->assertSessionHas('resrv_reservation', 1);

        $this->assertDatabaseHas('resrv_reservations', [
            'payment' => $payment,
            'price' => $total,
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 2,
        ]);
    }

    public function test_multi_dates_reservation()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(20, 'day')->toISOString(),
            'price' => 50,
            'available' => 2,
        ];

        $this->post(cp_route('resrv.availability.update'), $payload);

        $extra = Extra::factory()->create();

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'dates' => [
                [
                    'date_start' => today()->setHour(12)->toISOString(),
                    'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
                ],
                [
                    'date_start' => today()->setHour(12)->add(3, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
                ],
                [
                    'date_start' => today()->setHour(12)->add(5, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(7, 'day')->toISOString(),
                ],
            ],
        ];

        $response = $this->post(route('resrv.availability.show', $item->id()), $searchPayload);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = $price + (json_decode($response->content())->request->days * $extra->price->format());

        $checkoutRequest = [
            'dates' => [
                [
                    'date_start' => today()->setHour(12)->toISOString(),
                    'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
                ],
                [
                    'date_start' => today()->setHour(12)->add(3, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
                ],
                [
                    'date_start' => today()->setHour(12)->add(5, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(7, 'day')->toISOString(),
                ],
            ],
            'payment' => $payment,
            'price' => $price,
            'extras' => [$extra->id => ['quantity' => 1]],
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee(1);

        $this->assertDatabaseHas('resrv_reservations', [
            'type' => 'parent',
        ]);
        $this->assertDatabaseHas('resrv_child_reservations', [
            'reservation_id' => 1,
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => 1,
            'extra_id' => $extra->id,
            'quantity' => 1,
        ]);
        // Check that only the needed availabities are decreased
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->isoFormat('YYYY-MM-DD'),
            'available' => 1,
        ]);
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->add(2, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
        ]);

        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();
        $this->post(route('resrv.availability.show', $item->id()), $searchPayload);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->isoFormat('YYYY-MM-DD'),
            'available' => 2,
        ]);
        $this->assertDatabaseHas('resrv_reservations', [
            'type' => 'parent',
            'status' => 'expired',
        ]);
    }

    public function test_advanced_availability_and_multi_dates_reservation()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->toISOString(),
            'date_end' => today()->add(8, 'day')->toISOString(),
            'price' => 50,
            'available' => 2,
            'advanced' => [['code' => 'something']],
        ];

        $response = $this->post(cp_route('resrv.advancedavailability.update'), $payload);
        $response->assertStatus(200);

        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'dates' => [
                [
                    'date_start' => today()->setHour(12)->toISOString(),
                    'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(3, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(5, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(7, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
            ],
        ];

        $response = $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);
        $response->assertStatus(200)->assertSee('200')->assertSee('message":{"status":1}}', false);

        $payment = json_decode($response->content())->data->payment;
        $price = json_decode($response->content())->data->price;
        $total = $price;

        $checkoutRequest = [
            'dates' => [
                [
                    'date_start' => today()->setHour(12)->toISOString(),
                    'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(3, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(4, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
                [
                    'date_start' => today()->setHour(12)->add(5, 'day')->toISOString(),
                    'date_end' => today()->setHour(12)->add(7, 'day')->toISOString(),
                    'advanced' => 'something',
                ],
            ],
            'payment' => $payment,
            'price' => $price,
            'total' => $total,
        ];

        $response = $this->post(route('resrv.reservation.confirm', $item->id()), $checkoutRequest);
        $response->assertStatus(200)->assertSee(1);

        $this->assertDatabaseHas('resrv_reservations', [
            'type' => 'parent',
        ]);
        $this->assertDatabaseHas('resrv_child_reservations', [
            'reservation_id' => 1,
        ]);
        // Check that only the needed availabities are decreased
        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->isoFormat('YYYY-MM-DD'),
            'available' => 1,
        ]);
        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->add(2, 'day')->isoFormat('YYYY-MM-DD'),
            'available' => 2,
        ]);

        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();
        $this->post(route('resrv.advancedavailability.show', $item->id()), $searchPayload);

        $this->assertDatabaseHas('resrv_advanced_availabilities', [
            'statamic_id' => $item->id(),
            'date' => today()->setHour(12)->isoFormat('YYYY-MM-DD'),
            'available' => 2,
        ]);
        $this->assertDatabaseHas('resrv_reservations', [
            'type' => 'parent',
            'status' => 'expired',
        ]);
    }

    public function test_array_of_stripe_keys()
    {
        Config::set('resrv-config.stripe_secret_key', [
            'pages' =>'sk_test_some-key',
            'other-collection' => 'sk_test_some-other-key',
        ]);

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
            'customer->first_name' => 'Test',
        ]);
    }
}

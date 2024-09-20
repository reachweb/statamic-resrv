<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationCreated as ReservationCreatedEvent;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
use Reach\StatamicResrv\Listeners\UpdateCouponAppliedToReservation;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class ReservationCheckoutTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    public $extras;

    public $entry;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'test@test.com',
            ],
        ]);

        $this->entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $this->entry->save();

        Config::set('resrv-config.checkout_completed_entry', $this->entry->id());
    }

    // Test if the checkout completed page loads correctly
    public function test_checkout_completed_page_loads()
    {
        $this->withStandardFakeViews();

        $this->get($this->entry->absoluteUrl())
            ->assertOk()
            ->assertSee($this->entry->title);
    }

    // Test if the checkout completed page shows success message
    public function test_checkout_completed_page_shows_success()
    {
        $this->withFakeViews();

        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ title }} {{ resrv_checkout_redirect }}{{ title }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?status=success')
            ->assertOk()
            ->assertSee('Payment successful');
    }

    // Test if the checkout completed page shows failure message
    public function test_checkout_completed_page_shows_failure()
    {
        $this->withFakeViews();

        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ title }} {{ resrv_checkout_redirect }}{{ title }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?status=error')
            ->assertOk()
            ->assertSee('Payment failed');
    }

    // Test if the checkout completed page shows pending message
    public function test_checkout_completed_page_shows_pending()
    {
        $this->withFakeViews();

        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ title }} {{ resrv_checkout_redirect }}{{ title }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?payment_pending=1')
            ->assertOk()
            ->assertSee('Reservation confirmed successfully');
    }

    // Test if webhook can confirm a reservation
    public function test_webhook_can_confirm_reservation()
    {
        Mail::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']))
            ->assertOk(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'confirmed',
        ]);
    }

    // Test if webhook fires reservation confirmed event
    public function test_webhook_fires_reservation_confirmed_event()
    {
        Mail::fake();
        Event::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']))
            ->assertOk(200);

        Event::assertDispatched(\Reach\StatamicResrv\Events\ReservationConfirmed::class, function ($event) {
            return $event->reservation->id === $this->reservation->id;
        });
    }

    // Test if the webhook will cancel a reservation for failed status
    public function test_webhook_can_cancel_reservation()
    {
        Mail::fake();
        Event::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'fail']))
            ->assertOk(200);

        Event::assertDispatched(\Reach\StatamicResrv\Events\ReservationCancelled::class, function ($event) {
            return $event->reservation->id === $this->reservation->id;
        });
    }

    // Test if email is sent when reservation is confirmed
    public function test_email_is_sent_when_reservation_is_confirmed()
    {
        Mail::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']));

        Mail::assertSent(ReservationConfirmed::class, function ($mail) {
            return $mail->reservation->id === $this->reservation->id;
        });

        Mail::assertSent(ReservationMade::class, function ($mail) {
            return $mail->reservation->id === $this->reservation->id;
        });
    }

    // Test if reservation email renders correct information
    public function test_reservation_email_renders_correct_information()
    {
        Config::set('resrv-config.admin_email', 'someone@test.com,someonelse@example.com');

        $mail = new ReservationConfirmed($this->reservation);
        $html = $mail->render();

        $mailMade = new ReservationMade($this->reservation);
        $htmlMade = $mailMade->render();

        $this->assertStringContainsString($this->entries->first()->title, $html);
        $this->assertStringContainsString($this->reservation->customer->get('email'), $html);
        $this->assertStringContainsString($this->reservation->customer->get('first_name'), $html);
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString($this->entries->first()->title, $htmlMade);
        $this->assertStringContainsString($this->reservation->customer->get('email'), $htmlMade);
        $this->assertStringContainsString($this->reservation->customer->get('first_name'), $htmlMade);
        $this->assertStringContainsString('500', $htmlMade);
    }

    // Test if it saves dynamic pricings that were applied
    public function test_it_saves_dynamic_pricings_that_were_applied()
    {
        $dynamic = DynamicPricing::factory()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'condition_value' => '1',
        ]);
        $dynamicFixed = DynamicPricing::factory()->fixedIncrease()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'condition_value' => '1',
        ]);

        $dynamicCoupon = DynamicPricing::factory()->withCoupon()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'condition_value' => '1',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamicFixed->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamicCoupon->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        session(['resrv_coupon' => $dynamicCoupon->coupon]);

        event(new ReservationCreatedEvent($this->reservation, new ReservationData(
            coupon: $dynamicCoupon->coupon,
        )));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamic->id,
            ]
        );

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamicFixed->id,
            ]
        );

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamicCoupon->id,
            ]
        );
    }

    // Test if listener listens to coupon updated event
    public function test_test_listener_listens_to_coupon_updated_event()
    {
        Event::fake();

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        event(new CouponUpdated($this->reservation, $dynamic->coupon));

        Event::assertListening(
            CouponUpdated::class,
            UpdateCouponAppliedToReservation::class,
        );
    }

    // Test if coupon updated event adds and removes coupon from reservation
    public function test_test_coupon_updated_event_adds_and_removes_coupon_from_reservation()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        event(new CouponUpdated($this->reservation, $dynamic->coupon));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamic->id,
            ]
        );

        $this->assertDatabaseCount('resrv_reservation_dynamic_pricing', 1);

        event(new CouponUpdated($this->reservation, $dynamic->coupon));

        $this->assertDatabaseCount('resrv_reservation_dynamic_pricing', 1);

        event(new CouponUpdated($this->reservation, $dynamic->coupon, true));

        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamic->id,
            ]
        );

        $this->assertDatabaseCount('resrv_reservation_dynamic_pricing', 0);
    }

    // Test if listener listens to reservation created event
    public function test_test_listener_listens_to_reservation_created_event()
    {
        Event::fake();

        $reservation = Reservation::factory()->create();

        event(new ReservationCreatedEvent($reservation));

        Event::assertListening(
            ReservationCreatedEvent::class,
            DecreaseAvailability::class,
        );

        Event::assertListening(
            ReservationCreatedEvent::class,
            AddReservationIdToSession::class,
        );
    }

    // Test if it saves affiliate when present in the event data
    public function test_it_saves_affiliate_when_present_in_the_event()
    {
        $this->withStandardFakeViews();

        $affiliate = Affiliate::factory()->create();

        event(new ReservationCreatedEvent($this->reservation, new ReservationData(
            affiliate: $affiliate,
        )));

        $this->assertDatabaseHas('resrv_reservation_affiliate',
            [
                'reservation_id' => 1,
                'affiliate_id' => $affiliate->id,
                'fee' => $affiliate->fee,
            ]
        );
    }

    // Test if email is sent to affiliate if send_reservation_email is enabled
    public function test_email_is_sent_to_affiliate_if_enabled()
    {
        Config::set('resrv-config.enable_affiliates', true);

        Mail::fake();

        $affiliate = Affiliate::factory()->create();

        DB::table('resrv_reservation_affiliate')->insert([
            'reservation_id' => $this->reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => $affiliate->fee,
        ]);

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']));

        Mail::assertSent(ReservationMade::class, function ($mail) use ($affiliate) {
            return $mail->hasTo($affiliate->email);
        });
    }

    // Test if email is not sent to affiliate if send_reservation_email is disabled
    public function test_email_is_not_sent_to_affiliate_if_disabled()
    {
        Config::set('resrv-config.enable_affiliates', true);

        Mail::fake();

        $affiliate = Affiliate::factory()->create([
            'send_reservation_email' => false,
        ]);

        DB::table('resrv_reservation_affiliate')->insert([
            'reservation_id' => $this->reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => $affiliate->fee,
        ]);

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']));

        Mail::assertNotSent(ReservationMade::class, function ($mail) use ($affiliate) {
            return $mail->hasTo($affiliate->email);
        });
    }
}

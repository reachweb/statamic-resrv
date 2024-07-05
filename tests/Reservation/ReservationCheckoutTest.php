<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Events\ReservationConfirmed as ReservationConfirmedEvent;
use Reach\StatamicResrv\Events\ReservationCreated as ReservationCreatedEvent;
use Reach\StatamicResrv\Listeners\AddReservationIdToSession;
use Reach\StatamicResrv\Listeners\DecreaseAvailability;
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

    /** @test */
    public function checkout_completed_page_loads()
    {
        $this->withStandardFakeViews();

        $this->get($this->entry->absoluteUrl())
            ->assertOk()
            ->assertSee($this->entry->title);
    }

    /** @test */
    public function checkout_completed_page_shows_success()
    {
        $this->withFakeViews();

        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ title }} {{ resrv_checkout_redirect }}{{ title }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?status=success')
            ->assertOk()
            ->assertSee('Payment successful');
    }

    /** @test */
    public function checkout_completed_page_shows_failure()
    {
        $this->withFakeViews();

        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ title }} {{ resrv_checkout_redirect }}{{ title }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?status=error')
            ->assertOk()
            ->assertSee('Payment failed');
    }

    /** @test */
    public function webhook_can_confirm_reservation()
    {
        Mail::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']))
            ->assertOk(200);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'confirmed',
        ]);
    }

    /** @test */
    public function webhook_fires_reservation_confirmed_event()
    {
        Mail::fake();
        Event::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'success']))
            ->assertOk(200);

        Event::assertDispatched(\Reach\StatamicResrv\Events\ReservationConfirmed::class, function ($event) {
            return $event->reservation->id === $this->reservation->id;
        });
    }

    /** @test */
    public function webhook_can_cancel_reservation()
    {
        Mail::fake();
        Event::fake();

        $this->post(route('resrv.webhook.store', ['reservation_id' => $this->reservation->id, 'status' => 'fail']))
            ->assertOk(200);

        Event::assertDispatched(\Reach\StatamicResrv\Events\ReservationCancelled::class, function ($event) {
            return $event->reservation->id === $this->reservation->id;
        });
    }

    /** @test */
    public function email_is_sent_when_reservation_is_confirmed()
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

    /** @test */
    public function it_saves_dynamic_pricings_that_were_applied()
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

        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        event(new ReservationConfirmedEvent($this->reservation));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing',
            [
                'reservation_id' => 1,
                'dynamic_pricing_id' => $dynamic->id,
            ]
        );
    }

    /** @test */
    public function test_listener_listens_to_reservation_created_event()
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

    /** @test */
    public function it_saves_affiliate_when_present_in_the_event()
    {
        $this->withStandardFakeViews();

        $affiliate = Affiliate::factory()->create();

        event(new ReservationCreatedEvent($this->reservation, $affiliate));

        $this->assertDatabaseHas('resrv_reservation_affiliate',
            [
                'reservation_id' => 1,
                'affiliate_id' => $affiliate->id,
                'fee' => $affiliate->fee,
            ]
        );
    }

    /** @test */
    public function email_is_sent_to_affiliate_if_enabled()
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

    /** @test */
    public function email_is_not_sent_to_affiliate_if_disabled()
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

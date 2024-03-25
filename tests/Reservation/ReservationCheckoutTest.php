<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
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
    }
}

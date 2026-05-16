<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\BuildingReservationEmail;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class BuildingReservationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_fires_once_when_mailable_builds()
    {
        Event::fake([BuildingReservationEmail::class]);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        (new ReservationConfirmed($reservation))->build();

        Event::assertDispatched(BuildingReservationEmail::class, 1);
        Event::assertDispatched(BuildingReservationEmail::class, function (BuildingReservationEmail $event) use ($reservation) {
            return $event->mailable instanceof ReservationConfirmed
                && $event->reservation?->id === $reservation->id;
        });
    }

    public function test_listener_can_attach_data_to_the_mailable()
    {
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Event::listen(BuildingReservationEmail::class, function (BuildingReservationEmail $event) {
            $event->mailable->attachData('payload-bytes', 'test.txt', ['mime' => 'text/plain']);
        });

        $mailable = new ReservationConfirmed($reservation);
        $mailable->build();

        $this->assertTrue(collect($mailable->rawAttachments)
            ->contains(fn ($attachment) => ($attachment['name'] ?? null) === 'test.txt'
                && ($attachment['data'] ?? null) === 'payload-bytes'));
    }
}

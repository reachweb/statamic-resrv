<?php

namespace Reach\StatamicResrv\Tests\Listeners;

use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AddDynamicPricingsToReservationTest extends TestCase
{
    use CreatesEntries;

    public function test_reservation_created_persists_the_coupon_to_the_session()
    {
        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $this->createAvailabilityForEntry($item, 150, 2, $rate->id, dates: [
            today()->isoFormat('YYYY-MM-DD'),
            today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
        ]);

        $reservation = Reservation::factory()
            ->withRate($rate->id)
            ->create(['item_id' => $item->id()]);

        // The session is not pre-seeded, so this isolates the listener's own write.
        $this->assertNull(session('resrv_coupon'));

        Event::dispatch(new ReservationCreated($reservation, new ReservationData(coupon: 'TESTCOUPON')));

        $this->assertEquals('TESTCOUPON', session('resrv_coupon'));
    }
}

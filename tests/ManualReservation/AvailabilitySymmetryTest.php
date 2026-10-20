<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class AvailabilitySymmetryTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    private function entryWithStock(int $available = 2): Entry
    {
        return $this->makeStatamicItemWithAvailability(available: $available);
    }

    private function reservationFor($entry, array $attributes = []): Reservation
    {
        $rate = Rate::forEntry($entry->id())->first();

        return Reservation::factory()->withCustomer()->withRate($rate->id)->create(array_merge([
            'item_id' => $entry->id(),
        ], $attributes));
    }

    private function availableOn($entry, $date): int
    {
        return (int) Availability::where('statamic_id', $entry->id())
            ->where('date', '>=', $date->toDateString())
            ->where('date', '<', $date->copy()->addDay()->toDateString())
            ->first()
            ->available;
    }

    public function test_reservation_that_does_not_affect_availability_never_moves_stock()
    {
        Mail::fake();

        $entry = $this->entryWithStock(available: 2);
        $reservation = $this->reservationFor($entry, [
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'affects_availability' => false,
        ]);

        Event::dispatch(new ReservationCreated($reservation, new ReservationData(viaCp: true)));

        $this->assertEquals(2, $this->availableOn($entry, today()));
        $this->assertEquals(2, $this->availableOn($entry, today()->addDay()));

        // A reservation that never decremented must not increment on cancellation.
        $reservation->transitionTo(ReservationStatus::CANCELLED);
        Event::dispatch(new ReservationCancelled($reservation->fresh()));

        $this->assertEquals(2, $this->availableOn($entry, today()));
        $this->assertEquals(2, $this->availableOn($entry, today()->addDay()));
    }

    public function test_reservation_with_the_flag_enabled_decrements_and_restores_stock()
    {
        Mail::fake();

        $entry = $this->entryWithStock(available: 2);
        $reservation = $this->reservationFor($entry, [
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
            'affects_availability' => true,
        ]);

        Event::dispatch(new ReservationCreated($reservation, new ReservationData(viaCp: true)));

        $this->assertEquals(1, $this->availableOn($entry, today()));
        $this->assertEquals(1, $this->availableOn($entry, today()->addDay()));

        $reservation->transitionTo(ReservationStatus::CANCELLED);
        Event::dispatch(new ReservationCancelled($reservation->fresh()));

        $this->assertEquals(2, $this->availableOn($entry, today()));
        $this->assertEquals(2, $this->availableOn($entry, today()->addDay()));
    }

    public function test_frontend_reservation_with_default_data_still_moves_stock_and_sets_session()
    {
        $entry = $this->entryWithStock(available: 2);
        $reservation = $this->reservationFor($entry);

        $this->assertNull(session('resrv_reservation'));

        Event::dispatch(new ReservationCreated($reservation, new ReservationData));

        $this->assertEquals(1, $this->availableOn($entry, today()));
        $this->assertEquals($reservation->id, session('resrv_reservation'));
    }

    public function test_via_cp_reservations_do_not_leak_into_the_session()
    {
        $entry = $this->entryWithStock(available: 2);
        $reservation = $this->reservationFor($entry, [
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
        ]);

        Event::dispatch(new ReservationCreated($reservation, new ReservationData(viaCp: true)));

        $this->assertNull(session('resrv_reservation'));
    }

    public function test_skip_dynamic_pricings_skips_the_pivot_rows()
    {
        $entry = $this->entryWithStock(available: 2);

        $dynamic = DynamicPricing::factory()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'condition_value' => '1',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $entry->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        $skipped = $this->reservationFor($entry, [
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
        ]);

        Event::dispatch(new ReservationCreated($skipped, new ReservationData(viaCp: true, skipDynamicPricings: true)));

        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', [
            'reservation_id' => $skipped->id,
        ]);

        // Control: the same rule attaches when the flag is off.
        $applied = $this->reservationFor($entry);

        Event::dispatch(new ReservationCreated($applied, new ReservationData));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing', [
            'reservation_id' => $applied->id,
            'dynamic_pricing_id' => $dynamic->id,
        ]);
    }
}

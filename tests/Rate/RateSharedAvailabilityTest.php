<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class RateSharedAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    protected function createSharedSetup(int $baseAvailable = 5, ?int $maxAvailable = null): array
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'max_available' => $maxAvailable,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
                ['date' => $startDate->copy()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => $baseAvailable,
            ]);

        return [
            'entry' => $entry,
            'baseRate' => $baseRate,
            'sharedRate' => $sharedRate,
            'startDate' => $startDate,
        ];
    }

    protected function getBaseRateAvailabilities(array $setup, int $days = 2): Collection
    {
        return Availability::where('rate_id', $setup['baseRate']->id)
            ->where('date', '>=', $setup['startDate']->toDateString())
            ->where('date', '<', $setup['startDate']->copy()->addDays($days)->toDateString())
            ->get();
    }

    public function test_shared_rate_decrements_base_rate_availability()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Base rate's availability should be decremented, not shared rate's
        foreach ($this->getBaseRateAvailabilities($setup) as $availability) {
            $this->assertEquals(4, $availability->available);
            $this->assertContains('r1', $availability->pending);
        }
    }

    public function test_shared_rate_with_max_available_respects_cap()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 2);

        // Create two existing confirmed reservations for this shared rate
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Third reservation is just-created (simulates real flow where INSERT happens before decrement)
        $thirdReservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);

        // Should fail because max_available = 2 and 2 existing active reservations
        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $thirdReservation->id,
        );
    }

    public function test_max_available_is_enforced_per_day_for_partially_overlapping_reservations()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 2);

        // Two existing reservations occupy ONLY the second night, pushing that single day
        // to the cap of 2 while the first night stays free.
        foreach (range(1, 2) as $i) {
            Reservation::factory()->create([
                'item_id' => $setup['entry']->id(),
                'rate_id' => $setup['sharedRate']->id,
                'date_start' => $setup['startDate']->copy()->addDay()->toDateString(),
                'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
                'quantity' => 1,
                'status' => 'confirmed',
            ]);
        }

        // New booking spans both nights. The first night is free, but the second night is
        // already at capacity, so the per-day check must reject it.
        $newReservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $newReservation->id,
        );
    }

    public function test_shared_rate_cancellation_increments_base_rate()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        // Decrement first
        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Verify decrement happened
        foreach ($this->getBaseRateAvailabilities($setup) as $availability) {
            $this->assertEquals(4, $availability->available);
        }

        // Now increment (cancel)
        AvailabilityRepository::increment(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Should be back to original
        foreach ($this->getBaseRateAvailabilities($setup) as $availability) {
            $this->assertEquals(5, $availability->available);
            $this->assertNotContains('r1', $availability->pending);
        }
    }

    public function test_multiple_shared_rates_decrement_same_base()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10);

        $sharedRate2 = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-rate-2',
            'base_rate_id' => $setup['baseRate']->id,
        ]);

        // Decrement for first shared rate
        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Decrement for second shared rate
        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $sharedRate2->id,
            reservationId: 2,
        );

        // Base rate should have decreased by 2
        $availability = $this->getBaseRateAvailabilities($setup, days: 1)->first();
        $this->assertNotNull($availability);
        $this->assertEquals(8, $availability->available);
    }

    public function test_shared_rate_prevents_overbooking_when_base_pool_exhausted()
    {
        $setup = $this->createSharedSetup(baseAvailable: 1);

        // First booking succeeds
        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Second booking should fail
        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 2,
        );
    }

    public function test_independent_rate_decrement_works_normally()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
                'price' => 100,
                'available' => 3,
            ]);

        AvailabilityRepository::decrement(
            date_start: $startDate->toDateString(),
            date_end: $startDate->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $entry->id(),
            rateId: $rate->id,
            reservationId: 1,
        );

        $availabilities = Availability::where('rate_id', $rate->id)->get();
        foreach ($availabilities as $availability) {
            $this->assertEquals(2, $availability->available);
        }
    }

    public function test_shared_rate_availability_query_returns_base_rate_results()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        // Query availability using the shared rate's ID — should find base rate's rows
        $results = AvailabilityRepository::itemAvailableBetween(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
        )->get();

        $this->assertCount(1, $results);
        $this->assertEquals($setup['entry']->id(), $results->first()->statamic_id);
    }

    public function test_shared_rate_confirm_availability_and_price()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        $availability = new Availability;

        // Use shared rate ID — should resolve to base rate's availability rows
        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['sharedRate']->id,
            'price' => '200.00',
        ], $setup['entry']->id());

        $this->assertTrue($result);
    }

    public function test_max_available_allows_first_booking()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 1);

        // Create the reservation first (simulates real flow)
        $reservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);

        // First booking should succeed even with max_available = 1
        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $reservation->id,
        );

        foreach ($this->getBaseRateAvailabilities($setup) as $availability) {
            $this->assertEquals(9, $availability->available);
        }
    }

    public function test_exhausted_dates_treats_checkout_date_as_exclusive()
    {
        // Regression: getExhaustedDatesForRate was iterating with Carbon::daysUntil which is
        // inclusive of date_end, causing the checkout day to be flagged as exhausted and
        // blocking legitimate back-to-back bookings. date_end is exclusive across the engine.
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 1);

        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $exhausted = AvailabilityRepository::getExhaustedDatesForRate($setup['sharedRate']);

        $this->assertContains(
            $setup['startDate']->toDateString(),
            $exhausted->all(),
            'first night should be exhausted'
        );
        $this->assertContains(
            $setup['startDate']->copy()->addDay()->toDateString(),
            $exhausted->all(),
            'second night should be exhausted'
        );
        $this->assertNotContains(
            $setup['startDate']->copy()->addDays(2)->toDateString(),
            $exhausted->all(),
            'checkout day (date_end) must remain bookable for back-to-back reservations'
        );
    }

    public function test_get_exhausted_dates_is_bounded_to_the_requested_range()
    {
        // Only reservations overlapping the requested window should be loaded; out-of-window bookings must not leak in.
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 1);

        // In-window booking: exhausts the first searched night (max_available = 1).
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDay()->toDateString(),
            'quantity' => 1,
            'status' => 'confirmed',
        ]);

        // Out-of-window booking a year in the past.
        $pastStart = $setup['startDate']->copy()->subYear();
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $pastStart->toDateString(),
            'date_end' => $pastStart->copy()->addDay()->toDateString(),
            'quantity' => 1,
            'status' => 'confirmed',
        ]);

        $exhausted = AvailabilityRepository::getExhaustedDatesForRate(
            $setup['sharedRate'],
            1,
            $setup['startDate']->toDateString(),
            $setup['startDate']->copy()->addDays(3)->toDateString(),
        );

        $this->assertContains(
            $setup['startDate']->toDateString(),
            $exhausted->all(),
            'in-range exhausted night should be returned'
        );

        $this->assertNotContains(
            $pastStart->toDateString(),
            $exhausted->all(),
            'a reservation outside the requested window must not leak into the exhausted dates'
        );
    }

    public function test_get_exhausted_dates_treats_the_window_end_as_exclusive()
    {
        // date_end is exclusive: a booking on the last night is only counted when the window end is that night + 1 day.
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 1);

        $lastNight = $setup['startDate']->copy()->addDays(2);

        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $lastNight->toDateString(),
            'date_end' => $lastNight->copy()->addDay()->toDateString(),
            'quantity' => 1,
            'status' => 'confirmed',
        ]);

        $rangeStart = $setup['startDate']->toDateString();

        // Exclusive end one day past the last night (what the callers pass): the night is counted.
        $withExclusiveEnd = AvailabilityRepository::getExhaustedDatesForRate(
            $setup['sharedRate'], 1, $rangeStart, $lastNight->copy()->addDay()->toDateString()
        );
        $this->assertContains(
            $lastNight->toDateString(),
            $withExclusiveEnd->all(),
            'last night must be counted when the window end is exclusive (last night + 1 day)'
        );

        // Ending the window on the last night itself drops the booking (date_start < end is false).
        $withTooNarrowEnd = AvailabilityRepository::getExhaustedDatesForRate(
            $setup['sharedRate'], 1, $rangeStart, $lastNight->toDateString()
        );
        $this->assertNotContains(
            $lastNight->toDateString(),
            $withTooNarrowEnd->all(),
            'a window ending on the last night excludes a booking that starts that night'
        );
    }

    public function test_max_available_counts_quantity_not_rows()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 3);

        // One reservation with quantity = 2 uses 2 of the 3 slots
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'confirmed',
        ]);

        // Second reservation with quantity = 2 (total would be 4 > max_available 3)
        $reservation2 = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $reservation2->id,
        );
    }

    public function test_shared_rate_availability_query_via_rate_id_param()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        // Query using rate ID parameter with shared rate ID
        $results = AvailabilityRepository::itemAvailableBetween(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_shared_relative_rate_uses_base_availability_with_price_modifier()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRelativeRate = Rate::factory()->relative()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // Single-rate query with shared+relative rate should resolve to base availability
        $availability = new Availability;
        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $sharedRelativeRate->id,
            'price' => '180.00', // 2 * 90 (10% decrease from 100)
        ], $entry->id());

        $this->assertTrue($result);
    }

    public function test_shared_independent_rate_does_not_overwrite_base_rate_price()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        $dateStart = today()->addDay()->format('Y-m-d');
        $dateEnd = today()->addDays(3)->format('Y-m-d');

        // Create base rate availability via the CP endpoint
        $this->post(cp_route('resrv.availability.update'), [
            'statamic_id' => $entry->id(),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'price' => 100,
            'available' => 5,
            'rate_ids' => [$baseRate->id],
        ])->assertStatus(200);

        // Try to update availability via the shared rate with a different price
        $this->post(cp_route('resrv.availability.update'), [
            'statamic_id' => $entry->id(),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'price' => 250,
            'available' => 5,
            'rate_ids' => [$sharedRate->id],
        ])->assertStatus(200);

        // Base rate price must remain unchanged at 100
        $baseAvailabilities = Availability::where('rate_id', $baseRate->id)->get();
        $baseAvailabilities->each(function ($avail) {
            $this->assertEquals('100.00', $avail->price->format());
        });
    }

    public function test_confirm_availability_rejects_when_shared_rate_cap_exhausted()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 2);

        // Create two confirmed reservations — cap is now full
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        $availability = new Availability;

        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['sharedRate']->id,
            'price' => '200.00',
        ], $setup['entry']->id());

        $this->assertFalse($result);
    }

    public function test_confirm_availability_allows_when_shared_rate_cap_not_reached()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 3);

        // Only one reservation — cap of 3 not reached
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        $availability = new Availability;

        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['sharedRate']->id,
            'price' => '200.00',
        ], $setup['entry']->id());

        $this->assertTrue($result);
    }

    public function test_confirm_availability_ignores_cap_for_shared_rate_without_max_available()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: null);

        // Many reservations but no cap set
        for ($i = 0; $i < 5; $i++) {
            Reservation::factory()->create([
                'item_id' => $setup['entry']->id(),
                'rate_id' => $setup['sharedRate']->id,
                'date_start' => $setup['startDate']->toDateString(),
                'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
                'status' => 'confirmed',
            ]);
        }

        $availability = new Availability;

        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['sharedRate']->id,
            'price' => '200.00',
        ], $setup['entry']->id());

        $this->assertTrue($result);
    }

    public function test_shared_rate_visible_when_base_rate_unpublished()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => 'any',
        ], $entry->id());

        $this->assertTrue($result['message']['status']);

        // Single published rate returns flat data (AvailabilityItemResource unwraps single items)
        $data = $result['data'];
        $this->assertEquals($sharedRate->id, $data['rate_id']);
    }

    public function test_calendar_shows_adjusted_price_for_shared_relative_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRelativeRate = Rate::factory()->relative()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()->create([
            'statamic_id' => $entry->id(),
            'rate_id' => $baseRate->id,
            'date' => $startDate,
            'price' => 100,
            'available' => 5,
        ]);

        $calendar = (new Availability)->getAvailabilityCalendar($entry->id(), (string) $sharedRelativeRate->id);

        $this->assertNotEmpty($calendar);

        $dateKey = $startDate->format('Y-m-d');
        $this->assertArrayHasKey($dateKey, $calendar);

        // Price should be 90.00 (100 - 10%), not the base rate's 100.00
        $this->assertEquals('90.00', (string) $calendar[$dateKey]['price']);
        $this->assertEquals($sharedRelativeRate->id, $calendar[$dateKey]['rate_id']);
    }

    public function test_calendar_adjusts_price_for_independent_relative_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $relativeRate = Rate::factory()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()->create([
            'statamic_id' => $entry->id(),
            'rate_id' => $relativeRate->id,
            'date' => $startDate,
            'price' => 100,
            'available' => 5,
        ]);

        $calendar = (new Availability)->getAvailabilityCalendar($entry->id(), (string) $relativeRate->id);

        $this->assertNotEmpty($calendar);

        $dateKey = $startDate->format('Y-m-d');
        $this->assertArrayHasKey($dateKey, $calendar);

        // Price should be 75.00 (100 - 25%), not the raw 100.00
        $this->assertEquals('75.00', (string) $calendar[$dateKey]['price']);
    }

    public function test_calendar_rewrites_rate_id_for_shared_non_relative_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()->create([
            'statamic_id' => $entry->id(),
            'rate_id' => $baseRate->id,
            'date' => $startDate,
            'price' => 100,
            'available' => 5,
        ]);

        $calendar = (new Availability)->getAvailabilityCalendar($entry->id(), (string) $sharedRate->id);

        $dateKey = $startDate->format('Y-m-d');
        // Price unchanged, but rate_id should be the shared rate's ID
        $this->assertEquals('100.00', (string) $calendar[$dateKey]['price']);
        $this->assertEquals($sharedRate->id, $calendar[$dateKey]['rate_id']);
    }

    public function test_implicit_search_resolves_shared_rate_when_base_unpublished()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // Search without specifying a rate_id — should resolve to the published shared rate
        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ], $entry->id());

        $this->assertTrue($result['message']['status']);
        $this->assertEquals($sharedRate->id, $result['data']['rate_id']);
    }

    public function test_implicit_search_does_not_show_entry_outside_shared_rate_scope()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        // Shared rate that does NOT apply to this entry (apply_to_all false, no pivot)
        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
            'apply_to_all' => false,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // Search without rate — shared rate doesn't apply to this entry, should return empty
        $result = (new Availability)->getAvailabilityForEntry([
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ], $entry->id());

        $this->assertFalse($result['message']['status']);
    }

    public function test_max_available_counts_child_reservations()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 2);

        // Parent reservation uses a different rate
        $parentRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'parent-rate',
        ]);

        $parent = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $parentRate->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Two child reservations consume the shared rate's cap
        ChildReservation::factory()->withRate($setup['sharedRate']->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->withRate($setup['sharedRate']->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        // Third booking via a new parent+child should fail (max_available = 2, 2 children already active)
        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 999,
        );
    }

    public function test_max_available_ignores_child_reservations_with_terminal_parent()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 2);

        $parentRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'parent-rate',
        ]);

        // Refunded (terminal) parent — its children should not count toward cap
        $cancelledParent = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $parentRate->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'refunded',
        ]);

        ChildReservation::factory()->withRate($setup['sharedRate']->id)->create([
            'reservation_id' => $cancelledParent->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 2,
        ]);

        // Should succeed — the child's parent is cancelled so it doesn't count
        $newReservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $newReservation->id,
        );

        // Verify decrement happened
        foreach ($this->getBaseRateAvailabilities($setup) as $availability) {
            $this->assertEquals(9, $availability->available);
        }
    }

    public function test_max_available_counts_both_parent_and_child_reservations()
    {
        $setup = $this->createSharedSetup(baseAvailable: 10, maxAvailable: 3);

        // One direct parent reservation on the shared rate (counts 1)
        Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // One child reservation on the shared rate via a different parent (counts 1)
        $parentRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'parent-rate',
        ]);

        $parent = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $parentRate->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        ChildReservation::factory()->withRate($setup['sharedRate']->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        // Total active = 2 (1 parent + 1 child). Trying to add quantity 2 would exceed cap of 3.
        $newReservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $newReservation->id,
        );
    }

    public function test_browse_collection_batches_shared_rate_capacity_checks_across_entries()
    {
        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        // Collection-wide shared rate with a cap; previously triggered a per-entry reservation query.
        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-capped',
            'base_rate_id' => $baseRate->id,
            'max_available' => 5,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        $addEntryWithAvailability = function () use ($baseRate, $startDate) {
            $entry = $this->makeStatamicItemWithResrvAvailabilityField();

            Availability::factory()
                ->count(3)
                ->sequence(
                    ['date' => $startDate],
                    ['date' => $startDate->copy()->addDay()],
                    ['date' => $startDate->copy()->addDays(2)],
                )
                ->create([
                    'statamic_id' => $entry->id(),
                    'rate_id' => $baseRate->id,
                    'price' => 100,
                    'available' => 10,
                ]);
        };

        $searchData = [
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ];

        $countReservationQueries = function () use ($searchData) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $result = app(Availability::class)->getAvailable($searchData);
            $this->assertTrue($result['message']['status']);

            $count = collect(DB::getQueryLog())
                ->filter(fn ($query) => str_contains($query['query'], 'resrv_reservations') || str_contains($query['query'], 'resrv_child_reservations'))
                ->count();

            DB::disableQueryLog();

            return $count;
        };

        $addEntryWithAvailability();
        $addEntryWithAvailability();
        $queriesForTwoEntries = $countReservationQueries();

        $addEntryWithAvailability();
        $addEntryWithAvailability();
        $queriesForFourEntries = $countReservationQueries();

        // Exhausted dates are resolved once per search, not once per entry.
        $this->assertSame(
            $queriesForTwoEntries,
            $queriesForFourEntries,
            'Shared-rate capacity checks must not issue a reservation query per browsed entry.'
        );
    }

    public function test_browse_collection_batches_shared_independent_pricing_queries_across_entries()
    {
        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        // Shared rate with its own per-date price overrides (independent pricing).
        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-independent',
            'base_rate_id' => $baseRate->id,
            'require_price_override' => false,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        // Each entry overrides only the first night, so the resolver must also fall back to the
        // base-rate price for the second night — exercising both per-item queries pre-batching.
        $addEntryWithAvailability = function () use ($baseRate, $sharedRate, $startDate) {
            $entry = $this->makeStatamicItemWithResrvAvailabilityField();

            Availability::factory()
                ->count(2)
                ->sequence(
                    ['date' => $startDate],
                    ['date' => $startDate->copy()->addDay()],
                )
                ->create([
                    'statamic_id' => $entry->id(),
                    'rate_id' => $baseRate->id,
                    'price' => 100,
                    'available' => 10,
                ]);

            RatePrice::create([
                'rate_id' => $sharedRate->id,
                'statamic_id' => $entry->id(),
                'date' => $startDate->toDateString(),
                'price' => 50,
            ]);

            return $entry;
        };

        $searchData = [
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ];

        $countPricingQueries = function () use ($searchData) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $result = app(Availability::class)->getAvailable($searchData);
            $this->assertTrue($result['message']['status']);

            $log = collect(DB::getQueryLog());
            $counts = [
                'rate_prices' => $log->filter(fn ($query) => str_contains($query['query'], 'resrv_rate_prices'))->count(),
                'availabilities' => $log->filter(fn ($query) => str_contains($query['query'], 'select') && str_contains($query['query'], 'resrv_availabilities'))->count(),
            ];

            DB::disableQueryLog();

            return [$counts, $result];
        };

        $addEntryWithAvailability();
        $addEntryWithAvailability();
        [$twoEntryCounts, $twoEntryResult] = $countPricingQueries();

        $addEntryWithAvailability();
        $addEntryWithAvailability();
        [$fourEntryCounts] = $countPricingQueries();

        // Override and base-price lookups are batched once per search, not once per browsed entry.
        $this->assertSame(
            $twoEntryCounts['rate_prices'],
            $fourEntryCounts['rate_prices'],
            'Shared-independent override lookups must not issue a rate-price query per browsed entry.'
        );
        $this->assertSame(
            $twoEntryCounts['availabilities'],
            $fourEntryCounts['availabilities'],
            'Base-rate price fallback must not issue an availability query per browsed entry.'
        );

        // Pricing is unchanged: first night uses the 50 override, second night falls back to base 100.
        $firstEntryId = $twoEntryResult['data']->keys()->first();
        $sharedOption = $twoEntryResult['data'][$firstEntryId]->firstWhere('rate_id', $sharedRate->id);
        $this->assertNotNull($sharedOption);
        $this->assertEquals('150.00', $sharedOption['price']);
    }

    public function test_browse_collection_excludes_exhausted_shared_rate_capacity()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        // Base rate unpublished, so only the shared rate (capped at 1) is a browse candidate.
        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-capped',
            'base_rate_id' => $baseRate->id,
            'max_available' => 1,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
                ['date' => $startDate->copy()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        $searchData = [
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
        ];

        // With the single slot free, the browse offers the shared rate.
        $this->assertTrue(app(Availability::class)->getAvailable($searchData)['message']['status']);

        // An active reservation fills the single slot for the searched dates.
        Reservation::factory()->create([
            'item_id' => $entry->id(),
            'rate_id' => $sharedRate->id,
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Cap exhausted — entry drops out of browse results.
        $this->assertFalse(app(Availability::class)->getAvailable($searchData)['message']['status']);
    }

    public function test_browse_collection_rejects_shared_rate_when_quantity_exceeds_cap()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        // Base rate unpublished, so only the shared rate (capped at 1) is a browse candidate.
        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-capped',
            'base_rate_id' => $baseRate->id,
            'max_available' => 1,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
                ['date' => $startDate->copy()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // quantity 2 exceeds cap of 1, so the rate must be rejected even with no reservations.
        $searchData = [
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => 2,
        ];

        $this->assertFalse(app(Availability::class)->getAvailable($searchData)['message']['status']);

        // The same dates with a quantity that fits the cap are still bookable.
        $searchData['quantity'] = 1;
        $this->assertTrue(app(Availability::class)->getAvailable($searchData)['message']['status']);
    }

    public function test_show_all_rates_calendar_rejects_shared_rate_when_quantity_exceeds_cap()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        // Base rate unpublished, so only the shared rate (capped at 1) is a calendar candidate.
        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'slug' => 'shared-capped',
            'base_rate_id' => $baseRate->id,
            'max_available' => 1,
            'published' => true,
        ]);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(3)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
                ['date' => $startDate->copy()->addDays(2)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $baseRate->id,
                'price' => 100,
                'available' => 5,
            ]);

        // quantity 2 exceeds cap of 1; all-rates calendar must return nothing.
        $exceedsCap = app(Availability::class)->getAvailableDatesFromDate(
            $entry->id(), $startDate->toDateString(), 2, null, true,
        );
        $this->assertEmpty($exceedsCap);

        // A quantity that fits the cap still renders the calendar dates.
        $fitsCap = app(Availability::class)->getAvailableDatesFromDate(
            $entry->id(), $startDate->toDateString(), 1, null, true,
        );
        $this->assertNotEmpty($fitsCap);
    }

    public function test_selected_rate_calendar_rejects_shared_rate_when_quantity_exceeds_cap()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5, maxAvailable: 1);

        // No reservations; quantity 2 still exceeds cap of 1 — selected-rate calendar must return nothing.
        $exceedsCap = app(Availability::class)->getAvailableDatesFromDate(
            $setup['entry']->id(), $setup['startDate']->toDateString(), 2, $setup['sharedRate']->id,
        );
        $this->assertEmpty($exceedsCap);

        // A quantity that fits the cap still renders the calendar dates.
        $fitsCap = app(Availability::class)->getAvailableDatesFromDate(
            $setup['entry']->id(), $setup['startDate']->toDateString(), 1, $setup['sharedRate']->id,
        );
        $this->assertNotEmpty($fitsCap);
    }

    public function test_empty_calendar_result_skips_unbounded_exhausted_date_scan()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5, maxAvailable: 1);

        // Window with no availability rows — exhausted-date lookup must be skipped entirely.
        $emptyWindowStart = $setup['startDate']->copy()->addDays(30)->toDateString();

        DB::enableQueryLog();

        $result = app(Availability::class)->getAvailableDatesFromDate(
            $setup['entry']->id(),
            $emptyWindowStart,
            1,
            $setup['sharedRate']->id,
        );

        $reservationQueries = collect(DB::getQueryLog())
            ->filter(fn ($entry) => str_contains($entry['query'], 'resrv_reservations'));

        DB::disableQueryLog();

        $this->assertEmpty($result);
        $this->assertCount(0, $reservationQueries, 'No reservation-overlap query should run for an empty result set.');
    }

    public function test_composite_overlap_indexes_exist_on_reservation_tables()
    {
        // The rate_id + date-range overlap checks rely on these composite indexes (M38).
        $this->assertReservationIndexExists('resrv_reservations', ['rate_id', 'date_start', 'date_end']);
        $this->assertReservationIndexExists('resrv_child_reservations', ['rate_id', 'date_start', 'date_end']);
    }

    protected function assertReservationIndexExists(string $table, array $columns): void
    {
        $hasIndex = collect(Schema::getIndexes($table))
            ->contains(fn ($index) => $index['columns'] === $columns);

        $this->assertTrue($hasIndex, "Expected a composite index on {$table} (".implode(', ', $columns).').');
    }
}

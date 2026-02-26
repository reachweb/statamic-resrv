<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
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

    protected function getBaseRateAvailabilities(array $setup, int $days = 2): \Illuminate\Database\Eloquent\Collection
    {
        return Availability::where('rate_id', $setup['baseRate']->id)
            ->where('date', '>=', $setup['startDate']->toDateString())
            ->where('date', '<', $setup['startDate']->copy()->addDays($days)->toDateString())
            ->get();
    }

    public function test_shared_rate_decrements_base_rate_availability()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        AvailabilityRepository::decrementForRate(
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
            $this->assertContains(1, $availability->pending);
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

        AvailabilityRepository::decrementForRate(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $thirdReservation->id,
        );
    }

    public function test_shared_rate_cancellation_increments_base_rate()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        // Decrement first
        AvailabilityRepository::decrementForRate(
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
        AvailabilityRepository::incrementForRate(
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
            $this->assertNotContains(1, $availability->pending);
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
        AvailabilityRepository::decrementForRate(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Decrement for second shared rate
        AvailabilityRepository::decrementForRate(
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
        AvailabilityRepository::decrementForRate(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 1,
        );

        // Second booking should fail
        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrementForRate(
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

        AvailabilityRepository::decrementForRate(
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
        $results = AvailabilityRepository::itemAvailableBetweenForRate(
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

        $availability = new \Reach\StatamicResrv\Models\Availability;

        // Use shared rate ID — should resolve to base rate's availability rows
        $result = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'advanced' => (string) $setup['sharedRate']->id,
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
        AvailabilityRepository::decrementForRate(
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

        AvailabilityRepository::decrementForRate(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: $reservation2->id,
        );
    }

    public function test_shared_rate_availability_query_via_advanced_param()
    {
        $setup = $this->createSharedSetup(baseAvailable: 5);

        // Query using the `advanced` array with shared rate ID
        $results = AvailabilityRepository::itemAvailableBetween(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            duration: 2,
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            advanced: [(string) $setup['sharedRate']->id],
        )->get();

        $this->assertCount(1, $results);
    }
}

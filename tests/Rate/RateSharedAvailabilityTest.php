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
            'statamic_id' => $entry->id(),
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'statamic_id' => $entry->id(),
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

        // Create a reservation to simulate an active booking for this shared rate
        $reservation = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Second reservation
        $reservation2 = Reservation::factory()->create([
            'item_id' => $setup['entry']->id(),
            'rate_id' => $setup['sharedRate']->id,
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Third should fail because max_available = 2
        $this->expectException(AvailabilityException::class);

        AvailabilityRepository::decrementForRate(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 1,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['sharedRate']->id,
            reservationId: 3,
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
            'statamic_id' => $setup['entry']->id(),
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
            'statamic_id' => $entry->id(),
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
}

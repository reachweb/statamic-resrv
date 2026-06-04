<?php

namespace Reach\StatamicResrv\Tests\Listeners;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class PreventEntryDeletionWithActiveReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_entry_deletion_is_halted_with_active_reservation()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        Reservation::factory()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);

        $itemId = $item->id();
        $result = $item->delete();

        $this->assertFalse($result, 'Statamic entry deletion should be halted by the EntryDeleting listener');
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $itemId,
        ]);
    }

    public function test_entry_deletion_proceeds_without_active_reservations()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        $itemId = $item->id();

        $item->delete();

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $itemId,
        ]);
    }

    public function test_entry_deletion_proceeds_when_reservation_is_terminal()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        Reservation::factory()->expired()->create([
            'item_id' => $item->id(),
            'rate_id' => $rate->id,
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
        ]);

        $itemId = $item->id();

        $item->delete();

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $itemId,
        ]);
    }

    public function test_listener_ignores_entries_without_resrv_availability_field()
    {
        $item = $this->makeStatamicWithoutResrvAvailabilityField();
        $itemId = $item->id();

        $result = $item->delete();

        $this->assertNotFalse($result, 'Non-resrv entries should delete freely');
        $this->assertDatabaseMissing('resrv_entries', ['item_id' => $itemId]);
    }
}

<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityChangeCpTest extends TestCase
{
    use RefreshDatabase;

    public $item;

    public $rate;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('resrv-config.enable_activity_log', true);

        $this->signInAdmin();

        $this->item = $this->makeStatamicItem();
        $this->rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->addDay()->isoFormat('YYYY-MM-DD')],
            )
            ->create([
                'statamic_id' => $this->item->id(),
                'rate_id' => $this->rate->id,
                'available' => 2,
                'price' => 50,
            ]);
    }

    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'statamic_id' => $this->item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'price' => 50,
            'available' => 2,
            'rate_ids' => [$this->rate->id],
        ], $overrides);
    }

    public function test_a_range_edit_logs_only_the_changed_values_in_one_batch()
    {
        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload([
            'available' => 5,
        ]))->assertOk();

        // Price is unchanged, so only the two `available` rows are logged.
        $this->assertDatabaseCount('resrv_availability_changes', 2);
        $this->assertCount(1, AvailabilityChange::pluck('batch')->unique());

        $this->assertDatabaseHas('resrv_availability_changes', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 5,
            'reason' => 'cp_edit',
            'actor_id' => '1',
            'actor_name' => 'test@test.com',
        ]);
    }

    public function test_creating_new_dates_logs_create_rows_with_null_old_values()
    {
        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload([
            'date_start' => today()->addDays(5)->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDays(6)->isoFormat('YYYY-MM-DD'),
            'price' => 80,
            'available' => 4,
        ]))->assertOk();

        // Two new dates × two fields.
        $this->assertDatabaseCount('resrv_availability_changes', 4);

        $this->assertDatabaseHas('resrv_availability_changes', [
            'date' => today()->addDays(5)->isoFormat('YYYY-MM-DD'),
            'action' => 'create',
            'field' => 'price',
            'old_value' => null,
            'new_value' => 80,
            'reason' => 'cp_edit',
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'action' => 'create',
            'field' => 'available',
            'old_value' => null,
            'new_value' => 4,
        ]);
    }

    public function test_an_unchanged_submit_logs_nothing()
    {
        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload())
            ->assertOk();

        $this->assertDatabaseCount('resrv_availability_changes', 0);
    }

    public function test_editing_both_fields_logs_two_rows_per_date_in_one_batch()
    {
        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload([
            'price' => 75,
            'available' => 3,
        ]))->assertOk();

        $this->assertDatabaseCount('resrv_availability_changes', 4);
        $this->assertCount(1, AvailabilityChange::pluck('batch')->unique());

        $this->assertDatabaseHas('resrv_availability_changes', [
            'field' => 'price',
            'old_value' => 50,
            'new_value' => 75,
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 3,
        ]);
    }

    public function test_a_shared_independent_price_edit_logs_the_rate_price_override()
    {
        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $this->rate->id,
            'pricing_type' => 'independent',
        ]);

        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload([
            'price' => 99,
            'available' => null,
            'rate_ids' => [$sharedRate->id],
        ]))->assertOk();

        $this->assertDatabaseCount('resrv_availability_changes', 2);

        // The override belongs to the edited (shared) rate, not the base pool.
        $this->assertDatabaseHas('resrv_availability_changes', [
            'rate_id' => $sharedRate->id,
            'action' => 'create',
            'field' => 'price',
            'old_value' => null,
            'new_value' => 99,
            'reason' => 'cp_edit',
        ]);
    }

    public function test_deleting_availability_logs_delete_rows_with_the_last_values()
    {
        $this->deleteJson(cp_route('resrv.availability.delete'), [
            'statamic_id' => $this->item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->addDay()->isoFormat('YYYY-MM-DD'),
            'rate_ids' => [$this->rate->id],
        ])->assertOk();

        // Two rows × two fields with values.
        $this->assertDatabaseCount('resrv_availability_changes', 4);
        $this->assertCount(1, AvailabilityChange::pluck('batch')->unique());

        $this->assertDatabaseHas('resrv_availability_changes', [
            'action' => 'delete',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => null,
            'reason' => 'cp_delete',
            'actor_id' => '1',
        ]);
        $this->assertDatabaseHas('resrv_availability_changes', [
            'action' => 'delete',
            'field' => 'price',
            'old_value' => 50,
            'new_value' => null,
        ]);
    }

    public function test_clearing_stuck_pending_logs_the_restored_quantity()
    {
        $reservation = Reservation::factory()->withRate($this->rate->id)->create([
            'item_id' => $this->item->id(),
            'status' => 'expired',
            'quantity' => 2,
        ]);

        Availability::where('statamic_id', $this->item->id())
            ->whereDate('date', today())
            ->update(['pending' => json_encode(["r{$reservation->id}"])]);

        $this->postJson(cp_route('resrv.availability.clearStuckPending'), [
            'statamic_id' => $this->item->id(),
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'rate_id' => $this->rate->id,
        ])->assertOk();

        $this->assertDatabaseHas('resrv_availability_changes', [
            'statamic_id' => $this->item->id(),
            'rate_id' => $this->rate->id,
            'date' => today()->isoFormat('YYYY-MM-DD'),
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 4,
            'reason' => 'stuck_pending_cleared',
            'actor_id' => '1',
        ]);
    }

    public function test_nothing_is_logged_when_the_toggle_is_off()
    {
        Config::set('resrv-config.enable_activity_log', false);

        $this->postJson(cp_route('resrv.availability.update'), $this->updatePayload([
            'price' => 75,
            'available' => 3,
        ]))->assertOk();

        $this->assertDatabaseCount('resrv_availability_changes', 0);
        $this->assertDatabaseHas('resrv_availabilities', ['price' => 75, 'available' => 3]);
    }
}

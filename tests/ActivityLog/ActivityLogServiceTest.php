<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Reach\StatamicResrv\Enums\AvailabilityChangeReason;
use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ActivityLog;
use Reach\StatamicResrv\Tests\TestCase;

class ActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function change(array $overrides = []): array
    {
        return array_merge([
            'statamic_id' => 'entry-id',
            'rate_id' => 1,
            'date' => '2026-07-10',
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 1,
        ], $overrides);
    }

    public function test_the_activity_log_is_disabled_by_default()
    {
        $this->assertFalse(config('resrv-config.enable_activity_log'));
        $this->assertFalse(app(ActivityLog::class)->enabled());
    }

    public function test_nothing_is_written_when_the_toggle_is_off()
    {
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create(['item_id' => $item->id()]);

        $activityLog = app(ActivityLog::class);

        $activityLog->logAvailabilityChanges(AvailabilityChangeReason::CpEdit, [$this->change()]);
        $activityLog->logReservation($reservation, null, ReservationStatus::PENDING, ReservationLogReason::CheckoutStarted);

        $this->assertDatabaseCount('resrv_availability_changes', 0);
        $this->assertDatabaseCount('resrv_reservation_logs', 0);
    }

    public function test_availability_changes_are_written_in_one_batch()
    {
        Config::set('resrv-config.enable_activity_log', true);

        app(ActivityLog::class)->logAvailabilityChanges(
            AvailabilityChangeReason::ReservationCreated,
            [
                $this->change(),
                $this->change(['date' => '2026-07-11']),
            ],
            reservationId: 12,
        );

        $this->assertDatabaseCount('resrv_availability_changes', 2);

        $batches = AvailabilityChange::pluck('batch')->unique();
        $this->assertCount(1, $batches);

        $this->assertDatabaseHas('resrv_availability_changes', [
            'statamic_id' => 'entry-id',
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 1,
            'reason' => 'reservation_created',
            'reservation_id' => 12,
        ]);

        $this->assertNotNull(AvailabilityChange::first()->created_at);
    }

    public function test_no_op_updates_are_skipped()
    {
        Config::set('resrv-config.enable_activity_log', true);

        app(ActivityLog::class)->logAvailabilityChanges(AvailabilityChangeReason::CpEdit, [
            $this->change(['old_value' => 2, 'new_value' => 2]),
            $this->change(['old_value' => '2.00', 'new_value' => 2]),
            $this->change(['old_value' => 2, 'new_value' => 3]),
        ]);

        $this->assertDatabaseCount('resrv_availability_changes', 1);
        $this->assertDatabaseHas('resrv_availability_changes', ['new_value' => 3]);
    }

    public function test_create_and_delete_rows_are_not_skipped_for_matching_values()
    {
        Config::set('resrv-config.enable_activity_log', true);

        app(ActivityLog::class)->logAvailabilityChanges(AvailabilityChangeReason::CpEdit, [
            $this->change(['action' => 'create', 'old_value' => null, 'new_value' => null]),
            $this->change(['action' => 'delete', 'old_value' => null, 'new_value' => null]),
        ]);

        $this->assertDatabaseCount('resrv_availability_changes', 2);
    }

    public function test_reservation_log_records_the_transition_and_actor()
    {
        Config::set('resrv-config.enable_activity_log', true);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create(['item_id' => $item->id()]);

        app(ActivityLog::class)->logReservation(
            reservation: $reservation,
            from: ReservationStatus::PENDING,
            to: ReservationStatus::CONFIRMED,
            reason: ReservationLogReason::WebhookConfirmed,
            context: ['gateway' => 'fake'],
            actor: ['id' => '1', 'name' => 'Admin'],
        );

        $this->assertDatabaseHas('resrv_reservation_logs', [
            'reservation_id' => $reservation->id,
            'reference' => $reservation->reference,
            'status_from' => 'pending',
            'status_to' => 'confirmed',
            'reason' => 'webhook_confirmed',
            'actor_id' => '1',
            'actor_name' => 'Admin',
        ]);
    }

    public function test_a_failed_log_write_never_throws()
    {
        Config::set('resrv-config.enable_activity_log', true);

        Log::spy();

        Schema::drop('resrv_availability_changes');
        Schema::drop('resrv_reservation_logs');

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create(['item_id' => $item->id()]);

        $activityLog = app(ActivityLog::class);

        $activityLog->logAvailabilityChanges(AvailabilityChangeReason::CpEdit, [$this->change()]);
        $activityLog->logReservation($reservation, null, ReservationStatus::PENDING, ReservationLogReason::CheckoutStarted);

        Log::shouldHaveReceived('error')
            ->with('Resrv activity log write failed', \Mockery::type('array'))
            ->twice();
    }
}

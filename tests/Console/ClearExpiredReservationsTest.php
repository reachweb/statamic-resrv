<?php

namespace Reach\StatamicResrv\Tests\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ClearExpiredReservationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_expired_reservations_older_than_the_cutoff()
    {
        $old = $this->expiredReservationUpdatedDaysAgo(100);

        $this->artisan('resrv:clear-expired-reservations')
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $old->id]);
    }

    public function test_keeps_recently_expired_reservations()
    {
        $recent = $this->expiredReservationUpdatedDaysAgo(10);

        $this->artisan('resrv:clear-expired-reservations')
            ->expectsOutputToContain('No expired reservations to clear.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_keeps_non_expired_reservations_regardless_of_age()
    {
        $confirmed = Reservation::factory()->create(['status' => 'confirmed']);
        DB::table('resrv_reservations')->where('id', $confirmed->id)->update(['updated_at' => now()->subDays(365)]);

        $this->artisan('resrv:clear-expired-reservations')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $confirmed->id]);
    }

    public function test_days_option_controls_the_cutoff()
    {
        $recent = $this->expiredReservationUpdatedDaysAgo(10);

        $this->artisan('resrv:clear-expired-reservations', ['--days' => 0])
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_also_removes_related_pivot_and_child_rows()
    {
        $old = $this->expiredReservationUpdatedDaysAgo(100);

        DB::table('resrv_reservation_dynamic_pricing')->insert([
            'reservation_id' => $old->id,
            'dynamic_pricing_id' => 1,
            'data' => json_encode([]),
            'order' => 1,
        ]);
        DB::table('resrv_child_reservations')->insert([
            'reservation_id' => $old->id,
            'date_start' => now()->toDateTimeString(),
            'date_end' => now()->addDay()->toDateTimeString(),
            'quantity' => 1,
        ]);

        $this->artisan('resrv:clear-expired-reservations')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $old->id]);
        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', ['reservation_id' => $old->id]);
        $this->assertDatabaseMissing('resrv_child_reservations', ['reservation_id' => $old->id]);
    }

    public function test_dry_run_reports_without_deleting()
    {
        $old = $this->expiredReservationUpdatedDaysAgo(100);

        $this->artisan('resrv:clear-expired-reservations', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_negative_days_option_is_rejected()
    {
        $this->artisan('resrv:clear-expired-reservations', ['--days' => -5])
            ->expectsOutputToContain('non-negative integer')
            ->assertExitCode(1);
    }

    private function expiredReservationUpdatedDaysAgo(int $days): Reservation
    {
        $reservation = Reservation::factory()->expired()->create();

        DB::table('resrv_reservations')
            ->where('id', $reservation->id)
            ->update(['updated_at' => now()->subDays($days)]);

        return $reservation;
    }
}

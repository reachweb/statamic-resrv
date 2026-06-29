<?php

namespace Reach\StatamicResrv\Tests\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ClearExpiredReservationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_expired_reservations_older_than_the_cutoff()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        $this->artisan('resrv:clear-expired-reservations')
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $old->id]);
    }

    public function test_keeps_recently_expired_reservations()
    {
        $recent = $this->expiredReservationCreatedDaysAgo(10);

        $this->artisan('resrv:clear-expired-reservations')
            ->expectsOutputToContain('No expired reservations to clear.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_keeps_non_expired_reservations_regardless_of_age()
    {
        $confirmed = Reservation::factory()->create(['status' => 'confirmed']);
        DB::table('resrv_reservations')->where('id', $confirmed->id)->update(['created_at' => now()->subDays(365)]);

        $this->artisan('resrv:clear-expired-reservations')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $confirmed->id]);
    }

    public function test_days_option_controls_the_cutoff()
    {
        $recent = $this->expiredReservationCreatedDaysAgo(10);

        $this->artisan('resrv:clear-expired-reservations', ['--days' => 0])
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_retention_is_based_on_created_at_not_updated_at()
    {
        // Simulates an abandoned-email send bumping updated_at long after expiry: an old
        // reservation must still be cleared, not handed a fresh retention window.
        $reservation = Reservation::factory()->expired()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now(),
        ]);

        $this->artisan('resrv:clear-expired-reservations')
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $reservation->id]);
    }

    public function test_also_removes_related_pivot_and_child_rows()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

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
        $old = $this->expiredReservationCreatedDaysAgo(100);

        $this->artisan('resrv:clear-expired-reservations', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_negative_days_option_is_rejected()
    {
        $this->artisan('resrv:clear-expired-reservations', ['--days' => -5])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);
    }

    public function test_non_numeric_days_option_is_rejected_without_deleting_anything()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        $this->artisan('resrv:clear-expired-reservations', ['--days' => 'abc'])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);

        // A bad value must not fall through to a "now" cutoff that wipes everything.
        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_excessively_large_days_option_is_rejected_without_deleting_anything()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        // An all-digit value that would overflow now()->subDays() and wrap the cutoff forward.
        $this->artisan('resrv:clear-expired-reservations', ['--days' => '9223372036854775807'])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_removes_the_orphaned_customer_when_clearing_a_reservation()
    {
        $reservation = Reservation::factory()->expired()->withCustomer()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update(['created_at' => now()->subDays(100)]);

        $this->assertDatabaseHas('resrv_customers', ['id' => $reservation->customer_id]);

        $this->artisan('resrv:clear-expired-reservations')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseMissing('resrv_customers', ['id' => $reservation->customer_id]);
    }

    public function test_keeps_a_customer_still_referenced_by_another_reservation()
    {
        $customer = Customer::factory()->create();

        $expired = Reservation::factory()->expired()->create(['customer_id' => $customer->id]);
        DB::table('resrv_reservations')->where('id', $expired->id)->update(['created_at' => now()->subDays(100)]);

        $active = Reservation::factory()->create(['status' => 'confirmed', 'customer_id' => $customer->id]);

        $this->artisan('resrv:clear-expired-reservations')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $expired->id]);
        $this->assertDatabaseHas('resrv_reservations', ['id' => $active->id]);
        $this->assertDatabaseHas('resrv_customers', ['id' => $customer->id]);
    }

    private function expiredReservationCreatedDaysAgo(int $days): Reservation
    {
        $reservation = Reservation::factory()->expired()->create();

        DB::table('resrv_reservations')
            ->where('id', $reservation->id)
            ->update(['created_at' => now()->subDays($days)]);

        return $reservation;
    }
}

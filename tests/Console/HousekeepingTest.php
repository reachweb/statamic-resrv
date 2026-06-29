<?php

namespace Reach\StatamicResrv\Tests\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class HousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private ?int $rateId = null;

    public function test_clears_expired_reservations_older_than_the_cutoff()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $old->id]);
    }

    public function test_keeps_recently_expired_reservations()
    {
        $recent = $this->expiredReservationCreatedDaysAgo(10);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 0 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_keeps_non_expired_reservations_regardless_of_age()
    {
        $confirmed = Reservation::factory()->create(['status' => 'confirmed']);
        DB::table('resrv_reservations')->where('id', $confirmed->id)->update(['created_at' => now()->subDays(365)]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $confirmed->id]);
    }

    public function test_days_option_controls_the_cutoff()
    {
        $recent = $this->expiredReservationCreatedDaysAgo(10);

        $this->artisan('resrv:housekeeping', ['--days' => 0])
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $recent->id]);
    }

    public function test_keeps_a_just_expired_reservation_until_the_expiration_grace_passes()
    {
        // An old pending hold that only just expired: created_at is already past retention, but
        // updated_at (its expiry time) is now. The IncreaseAvailability listener may still be
        // restoring inventory, so the purge must leave it alone this run.
        $reservation = Reservation::factory()->expired()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now(),
        ]);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 0 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id]);
    }

    public function test_clears_an_old_reservation_once_the_expiration_grace_has_passed()
    {
        // Retention is still governed by created_at, not updated_at: a reservation updated only a
        // couple of days ago (well within --days) is still cleared because created_at is old and
        // the short expiration grace has passed — a recent updated_at never resets the window.
        $reservation = Reservation::factory()->expired()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(2),
        ]);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 1 expired reservation')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $reservation->id]);
    }

    public function test_also_removes_related_pivot_and_child_rows()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        DB::table('resrv_reservation_affiliate')->insert([
            'reservation_id' => $old->id,
            'affiliate_id' => 1,
            'fee' => 10,
        ]);
        DB::table('resrv_reservation_dynamic_pricing')->insert([
            'reservation_id' => $old->id,
            'dynamic_pricing_id' => 1,
            'data' => json_encode([]),
            'order' => 1,
        ]);
        DB::table('resrv_reservation_option')->insert([
            'reservation_id' => $old->id,
            'option_id' => 1,
            'value' => 1,
        ]);
        DB::table('resrv_reservation_extra')->insert([
            'reservation_id' => $old->id,
            'extra_id' => 1,
            'quantity' => 1,
        ]);
        DB::table('resrv_child_reservations')->insert([
            'reservation_id' => $old->id,
            'date_start' => now()->toDateTimeString(),
            'date_end' => now()->addDay()->toDateTimeString(),
            'quantity' => 1,
        ]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $old->id]);
        $this->assertDatabaseMissing('resrv_reservation_affiliate', ['reservation_id' => $old->id]);
        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', ['reservation_id' => $old->id]);
        $this->assertDatabaseMissing('resrv_reservation_option', ['reservation_id' => $old->id]);
        $this->assertDatabaseMissing('resrv_reservation_extra', ['reservation_id' => $old->id]);
        $this->assertDatabaseMissing('resrv_child_reservations', ['reservation_id' => $old->id]);
    }

    public function test_dry_run_reports_without_deleting()
    {
        $reservation = Reservation::factory()->expired()->withCustomer()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);
        DB::table('resrv_customers')->where('id', $reservation->customer_id)->update(['created_at' => now()->subDays(100)]);
        $this->pastAvailabilityRecord('dry-run-entry', now()->subDays(100));

        $this->artisan('resrv:housekeeping', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 expired reservation(s), 1 orphaned customer(s) and 1 past availability record(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseHas('resrv_customers', ['id' => $reservation->customer_id]);
        $this->assertDatabaseHas('resrv_availabilities', ['statamic_id' => 'dry-run-entry']);
    }

    public function test_dry_run_customer_prediction_respects_the_expiration_grace()
    {
        // Old reservation that only just expired (updated_at within the grace): the real run keeps
        // it and its customer, so the dry-run prediction must not count the customer as orphaned.
        $reservation = Reservation::factory()->expired()->withCustomer()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now(),
        ]);
        DB::table('resrv_customers')->where('id', $reservation->customer_id)->update(['created_at' => now()->subDays(100)]);

        $this->artisan('resrv:housekeeping', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 0 expired reservation(s), 0 orphaned customer(s)')
            ->assertExitCode(0);

        // The live run agrees with the prediction: both reservation and customer survive.
        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseHas('resrv_customers', ['id' => $reservation->customer_id]);
    }

    public function test_negative_days_option_is_rejected()
    {
        $this->artisan('resrv:housekeeping', ['--days' => -5])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);
    }

    public function test_non_numeric_days_option_is_rejected_without_deleting_anything()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        $this->artisan('resrv:housekeeping', ['--days' => 'abc'])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);

        // A bad value must not fall through to a "now" cutoff that wipes everything.
        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_excessively_large_days_option_is_rejected_without_deleting_anything()
    {
        $old = $this->expiredReservationCreatedDaysAgo(100);

        // An all-digit value that would overflow now()->subDays() and wrap the cutoff forward.
        $this->artisan('resrv:housekeeping', ['--days' => '9223372036854775807'])
            ->expectsOutputToContain('between 0 and')
            ->assertExitCode(1);

        $this->assertDatabaseHas('resrv_reservations', ['id' => $old->id]);
    }

    public function test_removes_the_orphaned_customer_when_clearing_a_reservation()
    {
        $reservation = Reservation::factory()->expired()->withCustomer()->create();
        // A real expired reservation and its customer are created together, so both age out.
        DB::table('resrv_reservations')->where('id', $reservation->id)->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);
        DB::table('resrv_customers')->where('id', $reservation->customer_id)->update(['created_at' => now()->subDays(100)]);

        $this->assertDatabaseHas('resrv_customers', ['id' => $reservation->customer_id]);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('1 orphaned customer(s)')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseMissing('resrv_customers', ['id' => $reservation->customer_id]);
    }

    public function test_removes_a_customer_shared_by_multiple_cleared_reservations()
    {
        $customer = Customer::factory()->create();
        DB::table('resrv_customers')->where('id', $customer->id)->update(['created_at' => now()->subDays(100)]);

        $first = Reservation::factory()->expired()->create(['customer_id' => $customer->id]);
        $second = Reservation::factory()->expired()->create(['customer_id' => $customer->id]);
        DB::table('resrv_reservations')->whereIn('id', [$first->id, $second->id])->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $first->id]);
        $this->assertDatabaseMissing('resrv_reservations', ['id' => $second->id]);
        $this->assertDatabaseMissing('resrv_customers', ['id' => $customer->id]);
    }

    public function test_keeps_a_customer_still_referenced_by_another_reservation()
    {
        $customer = Customer::factory()->create();
        DB::table('resrv_customers')->where('id', $customer->id)->update(['created_at' => now()->subDays(100)]);

        $expired = Reservation::factory()->expired()->create(['customer_id' => $customer->id]);
        DB::table('resrv_reservations')->where('id', $expired->id)->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);

        $active = Reservation::factory()->create(['status' => 'confirmed', 'customer_id' => $customer->id]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $expired->id]);
        $this->assertDatabaseHas('resrv_reservations', ['id' => $active->id]);
        $this->assertDatabaseHas('resrv_customers', ['id' => $customer->id]);
    }

    public function test_removes_an_orphaned_customer_created_more_recently_than_its_reservation()
    {
        // Checkout links the customer to the reservation only after Customer::create, so the
        // customer row is younger than its reservation and can sit well within the --days window
        // while the reservation is already past it. It must still clear the same run, gated only
        // by the short in-flight grace, not by --days.
        $reservation = Reservation::factory()->expired()->withCustomer()->create();
        DB::table('resrv_reservations')->where('id', $reservation->id)->update(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);
        DB::table('resrv_customers')->where('id', $reservation->customer_id)->update(['created_at' => now()->subDays(2)]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseMissing('resrv_customers', ['id' => $reservation->customer_id]);
    }

    public function test_self_heals_a_customer_stranded_by_an_earlier_interrupted_run()
    {
        // No reservation references this customer (e.g. its reservation was deleted by an
        // earlier run that crashed before cleanup). An old orphan must still be reclaimed.
        $orphan = Customer::factory()->create();
        DB::table('resrv_customers')->where('id', $orphan->id)->update(['created_at' => now()->subDays(100)]);

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_customers', ['id' => $orphan->id]);
    }

    public function test_keeps_a_recently_created_orphaned_customer()
    {
        // An in-flight checkout creates the customer before the reservation exists; the
        // created_at guard must keep that brand-new, still-reservation-less customer.
        $fresh = Customer::factory()->create();

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_customers', ['id' => $fresh->id]);
    }

    public function test_clears_availability_for_dates_older_than_the_cutoff()
    {
        $this->pastAvailabilityRecord('old-entry', now()->subDays(100));

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('1 past availability record(s)')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_availabilities', ['statamic_id' => 'old-entry']);
    }

    public function test_keeps_recent_and_future_availability()
    {
        $this->pastAvailabilityRecord('recent-entry', now()->subDays(10));
        $this->pastAvailabilityRecord('future-entry', now()->addDays(30));

        $this->artisan('resrv:housekeeping')->assertExitCode(0);

        $this->assertDatabaseHas('resrv_availabilities', ['statamic_id' => 'recent-entry']);
        $this->assertDatabaseHas('resrv_availabilities', ['statamic_id' => 'future-entry']);
    }

    public function test_availability_retention_follows_the_days_option()
    {
        $this->pastAvailabilityRecord('within-window', now()->subDays(10));

        $this->artisan('resrv:housekeeping', ['--days' => 0])->assertExitCode(0);

        $this->assertDatabaseMissing('resrv_availabilities', ['statamic_id' => 'within-window']);
    }

    private function expiredReservationCreatedDaysAgo(int $days): Reservation
    {
        $reservation = Reservation::factory()->expired()->create();

        // Age both timestamps: a reservation created N days ago really did expire ~N days ago, so
        // updated_at is old too and clears the in-flight-expiration grace.
        DB::table('resrv_reservations')
            ->where('id', $reservation->id)
            ->update(['created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);

        return $reservation;
    }

    private function pastAvailabilityRecord(string $statamicId, Carbon $date): void
    {
        $this->rateId ??= Rate::factory()->create()->id;

        DB::table('resrv_availabilities')->insert([
            'statamic_id' => $statamicId,
            'date' => $date->toDateString(),
            'available' => 5,
            'price' => 100,
            'rate_id' => $this->rateId,
        ]);
    }
}

<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Tests\TestCase;

class HousekeepingLogPruneTest extends TestCase
{
    use RefreshDatabase;

    private function seedLogRows(int $daysOld, int $count = 1): void
    {
        $createdAt = now()->subDays($daysOld);

        for ($i = 0; $i < $count; $i++) {
            DB::table('resrv_availability_changes')->insert([
                'batch' => (string) Str::uuid(),
                'statamic_id' => 'entry-id',
                'date' => today()->toDateString(),
                'action' => 'update',
                'field' => 'available',
                'old_value' => 2,
                'new_value' => 1,
                'reason' => 'cp_edit',
                'created_at' => $createdAt,
            ]);

            DB::table('resrv_reservation_logs')->insert([
                'reservation_id' => 1,
                'reference' => 'ABCDEF',
                'status_to' => 'pending',
                'reason' => 'checkout_started',
                'created_at' => $createdAt,
            ]);
        }
    }

    public function test_old_log_entries_are_pruned_and_recent_ones_kept()
    {
        $this->seedLogRows(daysOld: 400, count: 2);
        $this->seedLogRows(daysOld: 10);

        $this->artisan('resrv:housekeeping')
            ->expectsOutputToContain('Cleared 4 activity log entrie(s) older than 365 day(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('resrv_availability_changes', 1);
        $this->assertDatabaseCount('resrv_reservation_logs', 1);
    }

    public function test_the_log_days_option_overrides_the_default_window()
    {
        $this->seedLogRows(daysOld: 40);
        $this->seedLogRows(daysOld: 10);

        $this->artisan('resrv:housekeeping', ['--log-days' => 30])
            ->expectsOutputToContain('Cleared 2 activity log entrie(s) older than 30 day(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('resrv_availability_changes', 1);
        $this->assertDatabaseCount('resrv_reservation_logs', 1);
    }

    public function test_dry_run_reports_the_counts_without_deleting()
    {
        $this->seedLogRows(daysOld: 400, count: 3);

        $this->artisan('resrv:housekeeping', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 6 activity log entrie(s) older than 365 day(s) would be cleared.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('resrv_availability_changes', 3);
        $this->assertDatabaseCount('resrv_reservation_logs', 3);
    }

    public function test_an_invalid_log_days_value_aborts_the_run()
    {
        $this->seedLogRows(daysOld: 400);

        $this->artisan('resrv:housekeeping', ['--log-days' => 'abc'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('resrv_availability_changes', 1);
    }
}

<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Console\RunsInPlease;

class Housekeeping extends Command
{
    use RunsInPlease;

    /**
     * Upper bound for --days: comfortably larger than any real retention policy, yet small
     * enough that now()->subDays() can't overflow and wrap the cutoff into the future.
     */
    private const MAX_DAYS = 36500;

    /**
     * Reservations are deleted one ID batch at a time so each chunk is a single, bounded
     * transaction (no global ID accumulator, no table-wide locks on a large purge).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Grace window shielding an in-flight checkout from the orphaned-customer sweep. Checkout
     * commits the Customer row (CheckoutForm::saveCustomer) a moment before it links the row to
     * the reservation, so a brand-new customer is briefly reservation-less. This is deliberately
     * decoupled from --days: --days is the reservation/availability retention policy, whereas a
     * customer is orphaned PII the instant its last reservation is gone and should clear promptly.
     */
    private const CUSTOMER_GRACE_DAYS = 1;

    /**
     * Grace window shielding an in-flight expiration from the reservation purge. Reservation::expire()
     * commits the EXPIRED status, makes a payment-gateway cancel call, and only then dispatches
     * ReservationExpired, whose IncreaseAvailability listener lazily loads the child rows to restore
     * inventory. A reservation that has been pending a long time can be both past created_at retention
     * and only just expired, so without this a concurrent run could delete its children inside that
     * window and the listener would restore nothing — permanently shrinking availability. Must comfortably
     * exceed expire()'s post-commit work (the synchronous listener + the network cancel call).
     */
    private const EXPIRATION_GRACE_DAYS = 1;

    protected $signature = 'resrv:housekeeping
        {--days=30 : Retention window in days; expired reservations and availability for dates that passed more than this many days ago are cleared}
        {--log-days=365 : Retention window in days for activity log entries}
        {--dry-run : Report what would be cleared without deleting anything}';

    protected $description = 'Housekeeping: delete long-expired reservations (with their related rows and orphaned customer PII), availability for dates that have passed, and old activity log entries';

    public function handle(): int
    {
        $days = $this->validatedDays('days');
        $logDays = $this->validatedDays('log-days');

        if ($days === null || $logDays === null) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $customerCutoff = now()->subDays(self::CUSTOMER_GRACE_DAYS);
        $logCutoff = now()->subDays($logDays);

        if ($this->option('dry-run')) {
            return $this->reportDryRun($cutoff, $customerCutoff, $logCutoff, $days, $logDays);
        }

        $reservations = $this->clearExpiredReservations($cutoff);
        $customers = $this->clearOrphanedCustomers($customerCutoff);
        $availability = $this->clearPastAvailability($cutoff);
        $logs = $this->clearOldActivityLogs($logCutoff);

        $this->info("Cleared {$reservations} expired reservation(s), {$customers} orphaned customer(s) and {$availability} past availability record(s) older than {$days} day(s).");
        $this->info("Cleared {$logs} activity log entrie(s) older than {$logDays} day(s).");

        return self::SUCCESS;
    }

    private function validatedDays(string $option): ?int
    {
        $daysOption = (string) $this->option($option);

        // Validate before casting: (int) "abc" silently becomes 0, and a value large enough to
        // overflow now()->subDays() wraps the cutoff into the future — either way the cutoff
        // collapses and everything in range would be deleted.
        if (! ctype_digit($daysOption) || (int) $daysOption > self::MAX_DAYS) {
            $this->error("The --{$option} option must be a whole number between 0 and ".self::MAX_DAYS.'.');

            return null;
        }

        return (int) $daysOption;
    }

    private function reportDryRun(Carbon $cutoff, Carbon $customerCutoff, Carbon $logCutoff, int $days, int $logDays): int
    {
        $reservations = $this->expiredReservations($cutoff)->count();
        $customers = $this->orphanableCustomers($cutoff, $customerCutoff)->count();
        $availability = $this->pastAvailability($cutoff)->count();
        $logs = $this->oldActivityLogs($logCutoff)->sum(fn (QueryBuilder $query) => $query->count());

        $this->info("Dry run: {$reservations} expired reservation(s), {$customers} orphaned customer(s) and {$availability} past availability record(s) older than {$days} day(s) would be cleared.");
        $this->info("Dry run: {$logs} activity log entrie(s) older than {$logDays} day(s) would be cleared.");

        return self::SUCCESS;
    }

    private function clearExpiredReservations(Carbon $cutoff): int
    {
        $deleted = 0;

        $this->expiredReservations($cutoff)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($reservations) use (&$deleted): void {
                $ids = $reservations->pluck('id')->all();

                // The child/pivot tables have no cascade constraints, so detach related rows
                // explicitly. One batched DELETE per table over the chunk's IDs keeps each chunk a
                // single transaction instead of N DELETEs per reservation. Consider adding indexes
                // on reservation_id in these tables if housekeeping needs to run on large datasets.
                DB::transaction(function () use ($ids): void {
                    DB::table('resrv_reservation_affiliate')->whereIn('reservation_id', $ids)->delete();
                    DB::table('resrv_reservation_dynamic_pricing')->whereIn('reservation_id', $ids)->delete();
                    DB::table('resrv_reservation_option')->whereIn('reservation_id', $ids)->delete();
                    DB::table('resrv_reservation_extra')->whereIn('reservation_id', $ids)->delete();
                    DB::table('resrv_child_reservations')->whereIn('reservation_id', $ids)->delete();
                    DB::table('resrv_reservations')->whereIn('id', $ids)->delete();
                });

                $deleted += count($ids);
            });

        return $deleted;
    }

    private function clearOrphanedCustomers(Carbon $customerCutoff): int
    {
        // A normal checkout creates a dedicated customer row holding email and form PII. Once no
        // reservation references it, it is orphaned PII that should go. Runs AFTER the reservation
        // sweep above, so "no reservation" reflects the real post-deletion state — if a chunk
        // threw, the whole command aborts before reaching here, so a customer is never deleted
        // while a reservation still references it.
        //
        // This is a single set-based sweep: it holds no ID list (so it can neither exhaust PHP
        // memory nor blow a WHERE IN size limit) and it is self-healing — a customer stranded by
        // an interrupted earlier run is reclaimed on the next run, because the sweep keys off the
        // current committed state rather than IDs remembered from this run. The grace guard leaves
        // an in-flight checkout's brand-new customer untouched and makes concurrent runs converge.
        return Customer::query()
            ->whereDoesntHave('reservations')
            ->where('created_at', '<', $customerCutoff)
            ->delete();
    }

    private function orphanableCustomers(Carbon $cutoff, Carbon $customerCutoff): Builder
    {
        // Dry-run only: predict which customers the sweep would clear. Since dry-run deletes
        // nothing, it can't rely on post-deletion state, so it counts customers that would have
        // no reservation left once this run's expired reservations are gone (or already have
        // none) — i.e. with no reservation that survives this run — past the grace window.
        //
        // The closure must be the exact negation of expiredReservations()'s purge predicate, or
        // the count diverges from the real run: a reservation SURVIVES this run if it isn't
        // expired, is still within created_at retention, or is within the expiration grace.
        return Customer::query()
            ->where('created_at', '<', $customerCutoff)
            ->whereDoesntHave('reservations', function (Builder $query) use ($cutoff): void {
                $query->where('status', '!=', ReservationStatus::EXPIRED->value)
                    ->orWhere('created_at', '>=', $cutoff)
                    ->orWhere('updated_at', '>=', now()->subDays(self::EXPIRATION_GRACE_DAYS));
            });
    }

    private function clearPastAvailability(Carbon $cutoff): int
    {
        // Availability is the forward-looking inventory calendar: nothing references a row by id
        // and reports never read it, so dates older than the retention window are dead weight.
        // A single indexed range delete (the date column is indexed) needs no chunking.
        return $this->pastAvailability($cutoff)->delete();
    }

    private function expiredReservations(Carbon $cutoff): Builder
    {
        // Retention keys off the immutable created_at, not updated_at: sending an abandoned
        // reservation email bumps updated_at, which would otherwise reset the retention clock
        // and keep an already-old reservation (and its customer PII) around for another window.
        //
        // The separate, short updated_at grace is a safety floor against the expiration race
        // (see EXPIRATION_GRACE_DAYS): updated_at on an EXPIRED row is effectively its expiry
        // time, so skipping rows touched within the grace keeps the purge clear of in-flight
        // expirations while only briefly delaying cleanup — it never resets the created_at clock.
        return Reservation::where('status', ReservationStatus::EXPIRED->value)
            ->where('created_at', '<', $cutoff)
            ->where('updated_at', '<', now()->subDays(self::EXPIRATION_GRACE_DAYS));
    }

    private function pastAvailability(Carbon $cutoff): QueryBuilder
    {
        return DB::table('resrv_availabilities')->where('date', '<', $cutoff->toDateString());
    }

    /**
     * Prunes both activity log tables by created_at. Runs regardless of the enable_activity_log
     * toggle — a site that disabled the feature still wants old rows gone. Log rows have no FKs,
     * so a plain chunked delete per table is safe.
     */
    private function clearOldActivityLogs(Carbon $logCutoff): int
    {
        $deleted = 0;

        foreach ($this->oldActivityLogs($logCutoff) as $query) {
            while (true) {
                $count = (clone $query)
                    ->orderBy('id')
                    ->limit(self::CHUNK_SIZE)
                    ->delete();

                $deleted += $count;

                if ($count < self::CHUNK_SIZE) {
                    break;
                }
            }
        }

        return $deleted;
    }

    /** @return SupportCollection<int, QueryBuilder> One prunable query per activity log table. */
    private function oldActivityLogs(Carbon $logCutoff): SupportCollection
    {
        return collect(['resrv_availability_changes', 'resrv_reservation_logs'])
            ->map(fn (string $table) => DB::table($table)->where('created_at', '<', $logCutoff));
    }
}

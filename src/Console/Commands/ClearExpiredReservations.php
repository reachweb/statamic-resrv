<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Console\RunsInPlease;

class ClearExpiredReservations extends Command
{
    use RunsInPlease;

    /**
     * Upper bound for --days: comfortably larger than any real retention policy, yet small
     * enough that now()->subDays() can't overflow and wrap the cutoff into the future.
     */
    private const MAX_DAYS = 36500;

    protected $signature = 'resrv:clear-expired-reservations
        {--days=90 : Only clear expired reservations created at least this many days ago}
        {--dry-run : Report how many reservations would be cleared without deleting anything}';

    protected $description = 'Delete expired reservations (and their related rows) that have been expired for a while';

    public function handle(): int
    {
        $daysOption = (string) $this->option('days');

        // Validate before casting: (int) "abc" silently becomes 0, and a value large enough to
        // overflow now()->subDays() wraps the cutoff into the future — either way the cutoff
        // collapses and every expired reservation would be deleted.
        if (! ctype_digit($daysOption) || (int) $daysOption > self::MAX_DAYS) {
            $this->error('The --days option must be a whole number between 0 and '.self::MAX_DAYS.'.');

            return self::FAILURE;
        }

        $days = (int) $daysOption;

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = $this->expiredReservations($cutoff)->count();

            $this->info($count === 0
                ? 'No expired reservations to clear.'
                : "Dry run: {$count} expired reservation(s) older than {$days} day(s) would be cleared.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $customerIds = [];

        $this->expiredReservations($cutoff)->chunkById(100, function ($reservations) use (&$deleted, &$customerIds): void {
            $reservations->each(function (Reservation $reservation) use (&$deleted, &$customerIds): void {
                if (filled($reservation->customer_id)) {
                    $customerIds[$reservation->customer_id] = $reservation->customer_id;
                }

                // The reservation child/pivot tables have no cascade constraints, so detach
                // related rows explicitly to avoid trading one orphan problem for another.
                DB::transaction(function () use ($reservation): void {
                    $reservation->affiliate()->detach();
                    $reservation->dynamicPricings()->detach();
                    $reservation->options()->detach();
                    $reservation->extras()->detach();
                    $reservation->childs()->delete();
                    $reservation->delete();
                });

                $deleted++;
            });
        });

        // A normal checkout creates a dedicated customer row holding email and form PII; clear
        // it once no reservation references it. This runs only after every reservation deletion
        // above has committed: checking inside the loop would let two concurrent runs each still
        // see the other's not-yet-deleted reservation and skip a shared customer forever.
        $this->deleteOrphanedCustomers($customerIds);

        $this->info($deleted === 0
            ? 'No expired reservations to clear.'
            : "Cleared {$deleted} expired reservation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function expiredReservations(Carbon $cutoff): Builder
    {
        // Retention keys off the immutable created_at, not updated_at: sending an abandoned
        // reservation email bumps updated_at, which would otherwise reset the retention clock
        // and keep an already-old reservation (and its customer PII) around for another window.
        return Reservation::where('status', ReservationStatus::EXPIRED->value)
            ->where('created_at', '<', $cutoff);
    }

    /**
     * @param  array<int, int|string>  $customerIds
     */
    private function deleteOrphanedCustomers(array $customerIds): void
    {
        if ($customerIds === []) {
            return;
        }

        // A single correlated DELETE evaluated against committed state: each candidate is
        // removed only if no reservation still references it, so concurrent runs converge
        // instead of each skipping a shared customer.
        Customer::whereKey($customerIds)
            ->whereDoesntHave('reservations')
            ->delete();
    }
}

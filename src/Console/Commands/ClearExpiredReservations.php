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

        $this->expiredReservations($cutoff)->chunkById(100, function ($reservations) use (&$deleted): void {
            $reservations->each(function (Reservation $reservation) use (&$deleted): void {
                // The reservation child/pivot tables have no cascade constraints, so detach
                // related rows explicitly to avoid trading one orphan problem for another.
                DB::transaction(function () use ($reservation): void {
                    $customerId = $reservation->customer_id;

                    $reservation->affiliate()->detach();
                    $reservation->dynamicPricings()->detach();
                    $reservation->options()->detach();
                    $reservation->extras()->detach();
                    $reservation->childs()->delete();
                    $reservation->delete();

                    // A normal checkout creates a dedicated customer row holding email and
                    // form PII; clear it once no reservation references it, otherwise the
                    // cleanup strands personal data and grows the customer table unbounded.
                    $this->deleteCustomerIfOrphaned($customerId);
                });

                $deleted++;
            });
        });

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

    private function deleteCustomerIfOrphaned($customerId): void
    {
        if (blank($customerId)) {
            return;
        }

        if (Reservation::where('customer_id', $customerId)->exists()) {
            return;
        }

        Customer::whereKey($customerId)->delete();
    }
}

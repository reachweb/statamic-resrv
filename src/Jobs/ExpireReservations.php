<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\Carbon;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;

class ExpireReservations
{
    use Dispatchable;

    /**
     * @param  bool  $expireSessionHold  Frontend searches abandon their own hold; CP writes pass
     *                                   false to only prune stale holds.
     */
    public function __construct(protected bool $expireSessionHold = true) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Expire any unfinished hold from this session right away; returning to search abandons it by design.
        if ($this->expireSessionHold && session()->has('resrv_reservation')) {
            $this->expireSafely((new Reservation)->newQuery()->find(session('resrv_reservation')));
        }
        $holdMinutes = config('resrv-config.minutes_to_hold', false);

        if ($holdMinutes == false) {
            return;
        }

        // Filter in SQL (not PHP) — this prune runs on every availability search.
        Reservation::where('status', ReservationStatus::PENDING->value)
            ->where('created_at', '<', Carbon::now()->subMinutes($holdMinutes))
            ->get()
            ->each(fn (Reservation $reservation) => $this->expireSafely($reservation));
    }

    /**
     * Expire a reservation, logging failures without aborting the batch.
     */
    protected function expireSafely(?Reservation $reservation): void
    {
        if ($reservation === null) {
            return;
        }
        try {
            $reservation->expire();
        } catch (\Throwable $e) {
            Log::error('Failed to expire reservation', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

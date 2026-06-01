<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;

class ExpireReservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // If a user already started a reservation that he didn't finish, expire it right away.
        if (session()->has('resrv_reservation')) {
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

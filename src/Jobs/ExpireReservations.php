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
        if (config('resrv-config.minutes_to_hold', false) == false) {
            return;
        }
        $pending = Reservation::where('status', ReservationStatus::PENDING->value)->get();
        foreach ($pending as $reservation) {
            $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');
            if ($expireAt < Carbon::now()) {
                $this->expireSafely($reservation);
            }
        }
    }

    /**
     * Call expire() on a reservation and log any failure without aborting the batch. Bad rows
     * (e.g. intent cancel failures bubbling up) shouldn't stop other pending reservations from
     * being expired. Reservation::expire() no longer swallows exceptions itself.
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

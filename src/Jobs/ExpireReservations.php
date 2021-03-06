<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        // If a user already started a reservation that he didn't finish, expire it right away
        if (session()->has('resrv_reservation')) {
            $reservation = new Reservation;
            $reservation->expire(session('resrv_reservation'));
        }
        if (config('resrv-config.minutes_to_hold', false) == false) {
            return;
        }
        $pending = Reservation::where('status', 'pending')->get();
        foreach ($pending as $reservation) {
            $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');
            if ($expireAt < Carbon::now()) {
                $reservation->expire($reservation->id);
            }
        }
    }
}

<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;
use Carbon\Carbon;

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
            $reservation = Reservation::find(session('resrv_reservation'));
            if ($reservation->status == 'pending') {
                $reservation->expire();
            }
        }
        if (config('resrv-config.minutes_to_hold', false) == false) {
            return;
        }        
        $pending = Reservation::where('status', 'pending')->get();
        foreach ($pending as $reservation) {
            $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');            
            if ($expireAt < Carbon::now()) {
                $reservation->expire();
            }
        }
    }
}
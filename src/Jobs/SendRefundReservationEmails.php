<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\Reservation;

class SendRefundReservationEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Customer email
        Mail::to($this->reservation->customer->email)->send(new ReservationRefunded($this->reservation));
        // Admin emails if set
        if (config('resrv-config.admin_email') != false) {
            $admin_emails = explode(',', config('resrv-config.admin_email'));
            Mail::to($admin_emails)->send(new ReservationRefunded($this->reservation));
        }
    }
}

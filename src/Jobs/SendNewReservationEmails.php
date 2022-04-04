<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Reservation;

class SendNewReservationEmails implements ShouldQueue
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
        Mail::to($this->reservation->customer->get('email'))->send(new ReservationConfirmed($this->reservation));
        // Admin emails if set
        if (config('resrv-config.admin_email') != false) {
            $admin_emails = explode(',', config('resrv-config.admin_email'));
            foreach ($admin_emails as $email) {
                Mail::to($email)->send(new ReservationMade($this->reservation));
            }
        }
    }
}

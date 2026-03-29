<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

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
        /** @var ReservationEmailDispatcher $dispatcher */
        $dispatcher = app(ReservationEmailDispatcher::class);

        $dispatcher->send(
            $this->reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($this->reservation),
        );

        $dispatcher->send(
            $this->reservation,
            ReservationEmailEvent::AdminMade,
            new ReservationMade($this->reservation),
        );
    }
}

<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

class SendCustomerCancelledEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    public function handle(): void
    {
        /** @var ReservationEmailDispatcher $dispatcher */
        $dispatcher = app(ReservationEmailDispatcher::class);

        $dispatcher->send(
            $this->reservation,
            ReservationEmailEvent::CustomerCancelled,
            new ReservationCancelledCustomer($this->reservation),
        );
    }
}

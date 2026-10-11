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

    /**
     * Not constructor-promoted: payloads queued before this property existed carry no key for it,
     * and an uninitialized typed property would make handle() throw on unserialization.
     */
    protected bool $notifyCustomer = true;

    public function __construct(Reservation $reservation, bool $notifyCustomer = true)
    {
        $this->reservation = $reservation;
        $this->notifyCustomer = $notifyCustomer;
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

        if ($this->notifyCustomer) {
            $dispatcher->send(
                $this->reservation,
                ReservationEmailEvent::CustomerConfirmed,
                new ReservationConfirmed($this->reservation),
            );
        }

        $dispatcher->send(
            $this->reservation,
            ReservationEmailEvent::AdminMade,
            new ReservationMade($this->reservation),
        );
    }
}

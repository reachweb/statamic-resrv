<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

class ResendConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Resend only the customer confirmation email. The admin "reservation made"
     * notification is intentionally not re-sent, since this is a manual resend
     * for a customer who never received their original confirmation. The customer
     * is forced as the recipient so the resend always reaches them even when the
     * customer_confirmed event is configured to deliver elsewhere.
     *
     * @return void
     */
    public function handle()
    {
        /** @var ReservationEmailDispatcher $dispatcher */
        $dispatcher = app(ReservationEmailDispatcher::class);

        $dispatcher->sendToRecipients(
            $this->reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($this->reservation),
            [$this->reservation->customer?->email],
        );
    }
}

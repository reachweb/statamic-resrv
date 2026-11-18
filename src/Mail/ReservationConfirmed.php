<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // An offline checkout confirms before any money arrives, so this email is where the
        // customer gets the payment details.
        $awaitingOfflinePayment = $this->reservation->isOfflineCheckout();

        $this->with([
            'awaitingOfflinePayment' => $awaitingOfflinePayment,
            'offlineInstructions' => $awaitingOfflinePayment ? $this->offlineInstructions() : null,
        ]);

        $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.confirmed'));

        $this->dispatchBuildingEvent($this->reservation);

        return $this;
    }
}

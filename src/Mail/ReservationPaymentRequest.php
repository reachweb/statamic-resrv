<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationPaymentRequest extends Mailable
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
        $this->with([
            'amountDue' => $this->reservation->amountDue()->format(),
            'payUrl' => $this->reservation->customerPaymentUrl(),
            'isOffline' => $this->reservation->paymentGatewaySupportsManualConfirmation(),
        ]);

        $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.payment-request'));

        $this->dispatchBuildingEvent($this->reservation);

        return $this;
    }
}

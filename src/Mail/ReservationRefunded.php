<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationRefunded extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Build the message. The template renders a "cancelled" body when no money ever
     * reached a gateway; without an explicit subject Laravel would derive "Reservation
     * Refunded" from the class name and contradict it. A configured subject (applied
     * via applyResrvEmailConfig before send) always wins.
     *
     * @return $this
     */
    public function build()
    {
        if (! $this->subject) {
            $this->subject($this->reservation->hasGatewayPayment()
                ? __('Reservation Refunded')
                : __('Reservation Cancelled'));
        }

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.refunded'));
    }
}

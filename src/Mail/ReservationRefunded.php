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
     * Build the message. The template renders a "cancelled" body whenever the refund did not
     * return money automatically (no charge reached a gateway, or an offline gateway needs a
     * manual refund); without an explicit subject Laravel would derive "Reservation Refunded"
     * from the class name and contradict it. A configured subject (applied via
     * applyResrvEmailConfig before send) always wins.
     *
     * @return $this
     */
    public function build()
    {
        if (! $this->subject) {
            $this->subject($this->reservation->refundIsAutomatic()
                ? __('Reservation Refunded')
                : __('Reservation Cancelled'));
        }

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.refunded'));
    }
}

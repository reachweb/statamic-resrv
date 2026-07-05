<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCancelledCustomer extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Build the message. Without an explicit subject Laravel would derive
     * "Reservation Cancelled Customer" from the class name. A configured subject
     * (applied via applyResrvEmailConfig before send) always wins.
     *
     * @return $this
     */
    public function build()
    {
        if (! $this->subject) {
            $this->subject(__('Reservation Cancelled'));
        }

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled-customer'));
    }
}

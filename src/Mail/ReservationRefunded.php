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
        if ($this->getOption('to', 1)) {
            $this->to(explode(',', $this->getOption('to', 1)) , env('APP_NAME', ''));
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        if ($this->getOption('html', 2)) {
            return $this->markdown($this->getOption('html', 2));
        }
        return $this->markdown('statamic-resrv::email.reservations.refunded');
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationMade extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public $theme = 'statamic-resrv::email.theme.html.themes.resrv';
    public $paths = ['statamic-resrv::email.theme'];

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
        return $this->markdown('statamic-resrv::email.reservations.made');
    }
}
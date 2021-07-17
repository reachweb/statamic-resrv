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

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
        $this->subject($this->generateSubject($reservation));
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

    private function generateSubject($reservation)
    {
        return 'Reservation #'.$reservation->id.' ['.$reservation->entry['title']->value().'] ['.$reservation->date_start->format('Y-m-d').'] ['.$reservation->customer->get('email').']';
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationMade extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
        if ($this->getOption('from', 1)) {
            $this->from($this->getOption('from', 1), env('APP_NAME', ''));
        }
        if ($this->getOption('to', 1)) {
            $this->to(explode(',', $this->getOption('to', 1)), env('APP_NAME', ''));
        }
        $this->subject($this->generateSubject($reservation));
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        if ($this->getOption('html', 1)) {
            return $this->markdown($this->getOption('html', 1));
        }
        return $this->markdown('statamic-resrv::email.reservations.made');
    }

    private function generateSubject($reservation)
    {
        return 'Reservation #'.$reservation->id.' ['.$reservation->entry['title']->value().'] ['.$reservation->date_start->format('Y-m-d').'] ['.$reservation->customer->get('email').']';
    }
}

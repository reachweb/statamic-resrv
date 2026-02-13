<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Traits\HandlesFormOptions;

class ReservationAbandoned extends Mailable
{
    use HandlesFormOptions, Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
        if ($this->getOption('from', 3)) {
            $this->from(explode(',', $this->getOption('from', 3)), env('APP_NAME', ''));
        }
        if ($this->getOption('subject', 3)) {
            $this->subject($this->getOption('subject', 3));
        } else {
            $this->subject(__("We noticed you didn't complete your reservation"));
        }
    }

    public function build()
    {
        if ($this->getOption('html', 3)) {
            return $this->markdown($this->getOption('html', 3));
        }

        return $this->markdown('statamic-resrv::email.reservations.abandoned');
    }
}

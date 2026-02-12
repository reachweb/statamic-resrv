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
        if ($this->getOption('from')) {
            $this->from(explode(',', $this->getOption('from')), env('APP_NAME', ''));
        }
        if ($this->getOption('subject')) {
            $this->subject($this->getOption('subject'));
        } else {
            $this->subject(__("We noticed you didn't complete your reservation"));
        }
    }

    public function build()
    {
        if ($this->getOption('html')) {
            return $this->markdown($this->getOption('html'));
        }

        return $this->markdown('statamic-resrv::email.reservations.abandoned');
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Traits\HandlesFormOptions;

class ReservationMade extends Mailable
{
    use HandlesFormOptions, Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;

        $recipients = [];

        if ($this->getOption('from', 1)) {
            $this->from($this->getOption('from', 1), env('APP_NAME', ''));
        }

        if ($this->getOption('to', 1)) {
            $recipients = array_merge($recipients, explode(',', $this->getOption('to', 1)));
        } elseif (config('resrv-config.admin_email') != false) {
            $recipients = array_merge($recipients, explode(',', config('resrv-config.admin_email')));
        }

        if (config('resrv-config.enable_affiliates') && $reservation->affiliate->count() > 0) {
            $affiliate = $reservation->affiliate->first();
            if ($affiliate->send_reservation_email === true) {
                $recipients = array_merge($recipients, explode(',', $affiliate->email));
            }
        }

        $recipients = array_filter(array_unique($recipients));

        if (count($recipients) > 0) {
            $this->to($recipients);
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
        return 'Reservation #'.$reservation->id.' ['.$reservation->entry['title']->value().'] ['.$reservation->date_start->format('Y-m-d').'] ['.$reservation->customer->email.']';
    }
}

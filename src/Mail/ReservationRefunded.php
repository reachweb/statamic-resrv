<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Traits\HandlesFormOptions;

class ReservationRefunded extends Mailable
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

        if ($this->reservation->customer->email) {
            $recipients = array_merge($recipients, explode(',', $this->reservation->customer->email));
        }

        if ($this->getOption('to', 1)) {
            $recipients = array_merge($recipients, explode(',', $this->getOption('to', 1)));
        } elseif (config('resrv-config.admin_email') != false) {
            $recipients = array_merge($recipients, explode(',', config('resrv-config.admin_email')));
        }

        $recipients = array_filter(array_unique($recipients));

        if (count($recipients) > 0) {
            $this->to($recipients);
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

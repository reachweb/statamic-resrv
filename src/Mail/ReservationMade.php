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

        $this->subject($this->generateSubject($reservation));
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.made'));
    }

    private function generateSubject($reservation)
    {
        $entryTitle = data_get($reservation->entry, 'title');
        $title = is_object($entryTitle) && method_exists($entryTitle, 'value')
            ? (string) $entryTitle->value()
            : (string) ($entryTitle ?: '## Entry deleted ##');

        $customerEmail = (string) ($reservation->customer?->email ?: 'unknown@example.com');

        return 'Reservation #'.$reservation->id.' ['.$title.'] ['.$reservation->date_start->format('Y-m-d').'] ['.$customerEmail.']';
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Events\ReservationCancelled as ReservationCancelledEvent;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    /**
     * @param  ?string  $context  ReservationCancelledEvent::CONTEXT_* — a lapsed payment
     *                            hold switches the intro from "cancelled by the customer"
     *                            to "payment hold lapsed" wording.
     */
    public function __construct(Reservation $reservation, public ?string $context = null)
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
        $holdLapsed = $this->context === ReservationCancelledEvent::CONTEXT_HOLD_LAPSED;

        if ($holdLapsed && ! $this->subject) {
            $this->subject(__('Reservation cancelled — payment hold lapsed'));
        }

        $this->with(['holdLapsed' => $holdLapsed]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled'));
    }
}

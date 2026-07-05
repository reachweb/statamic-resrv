<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCancelledCustomer extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    /**
     * @param  ?string  $context  ReservationCancelled::CONTEXT_* — a lapsed payment hold
     *                            switches the subject and body copy (no money ever moved,
     *                            so the no-refund wording would be wrong).
     */
    public function __construct(Reservation $reservation, public ?string $context = null)
    {
        $this->reservation = $reservation;
    }

    /**
     * Build the message. Without an explicit subject Laravel would derive
     * "Reservation Cancelled Customer" from the class name. A configured subject
     * (applied via applyResrvEmailConfig before send) always wins.
     *
     * @return $this
     */
    public function build()
    {
        $holdLapsed = $this->context === ReservationCancelled::CONTEXT_HOLD_LAPSED;

        if (! $this->subject) {
            $this->subject($holdLapsed
                ? __('Reservation cancelled — payment not received')
                : __('Reservation Cancelled'));
        }

        $this->with(['holdLapsed' => $holdLapsed]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled-customer'));
    }
}

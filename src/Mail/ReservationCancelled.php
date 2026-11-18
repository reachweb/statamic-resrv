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

    /** @param  ?string  $context  ReservationCancelledEvent::CONTEXT_* — a lapsed hold switches the wording. */
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
        $paymentInFlight = $this->context === ReservationCancelledEvent::CONTEXT_PAYMENT_IN_FLIGHT;

        if ($holdLapsed && ! $this->subject) {
            $this->subject(__('Reservation cancelled — payment hold lapsed'));
        }

        // Only a gateway charge is retained: an unpaid hold collected nothing, and an intent that
        // captured as the hold was cancelled is refunded.
        $paymentCollected = ! $this->reservation->amountPaid()->isZero();

        $this->with([
            'holdLapsed' => $holdLapsed,
            'paymentInFlight' => $paymentInFlight,
            'paymentCollected' => $paymentCollected,
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled'));
    }
}

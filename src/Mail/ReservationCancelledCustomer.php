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

    /** @param  ?string  $context  ReservationCancelled::CONTEXT_* — a lapsed hold switches the subject and body copy. */
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
        $paymentInFlight = $this->context === ReservationCancelled::CONTEXT_PAYMENT_IN_FLIGHT;

        if (! $this->subject) {
            $this->subject($holdLapsed
                ? __('Reservation cancelled — payment not received')
                : __('Reservation Cancelled'));
        }

        // Only a gateway charge is retained: an unpaid hold collected nothing, and an intent that
        // captured as the hold was cancelled is refunded.
        $paymentCollected = ! $this->reservation->amountPaid()->isZero();

        $this->with([
            'holdLapsed' => $holdLapsed,
            'paymentInFlight' => $paymentInFlight,
            'paymentCollected' => $paymentCollected,
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled-customer'));
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;

class ReservationRefunded extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Build the message. The template renders a "cancelled" body whenever the refund did not
     * return money automatically (no charge reached a gateway, or an offline gateway needs a
     * manual refund); without an explicit subject Laravel would derive "Reservation Refunded"
     * from the class name and contradict it. A configured subject (applied via
     * applyResrvEmailConfig before send) always wins.
     *
     * An unresolved reference never counts as a card refund: it is a reconciliation handle on
     * a payment collected out of band, and the gateway may have been skipped.
     *
     * @return $this
     */
    public function build()
    {
        $refundedAutomatically = $this->reservation->refundIsAutomatic() && ! $this->reservation->payment_unresolved;

        if (! $this->subject) {
            $this->subject($refundedAutomatically
                ? __('Reservation Refunded')
                : __('Reservation Cancelled'));
        }

        $this->with([
            'refundedAutomatically' => $refundedAutomatically,
            'returnedSeparately' => $refundedAutomatically ? null : $this->returnedSeparately(),
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.refunded'));
    }

    /**
     * How the body speaks about money no gateway returns, on the CP's source of truth
     * (amountReceived()): 'promised' when the booking is known to have received it, 'hedged'
     * when receipt is unknown (null), null when nothing was received — never a promise for a
     * payment the system records as not collected, and never a hedge the CP contradicts.
     */
    protected function returnedSeparately(): ?string
    {
        $reservation = $this->reservation;

        if ($reservation->status !== ReservationStatus::REFUNDED->value || $reservation->amountDue()->isZero()) {
            return null;
        }

        $received = $reservation->amountReceived();

        if ($received !== null && $received->isZero()) {
            return null;
        }

        if ($received === null) {
            return 'hedged';
        }

        return 'promised';
    }
}

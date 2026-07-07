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

        // Whether money is actually being withheld. An unpaid hold (awaiting-payment cancel) never
        // captured anything even if a payment_id lingers from an opened-but-unpaid intent, so the
        // template must not tell the customer their payment is non-refundable in that case.
        $paymentCollected = ! $holdLapsed
            && $this->context !== ReservationCancelled::CONTEXT_UNPAID_HOLD
            && $this->reservation->hasGatewayPayment();

        $this->with([
            'holdLapsed' => $holdLapsed,
            'paymentCollected' => $paymentCollected,
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled-customer'));
    }
}

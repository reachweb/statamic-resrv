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

        if (! $this->subject) {
            $this->subject($holdLapsed
                ? __('Reservation cancelled — payment not received')
                : __('Reservation Cancelled'));
        }

        // A lapsed/unpaid hold never captured money even if an unpaid intent id lingers,
        // so the template must not call the payment non-refundable.
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

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

        if ($holdLapsed && ! $this->subject) {
            $this->subject(__('Reservation cancelled — payment hold lapsed'));
        }

        // A lapsed/unpaid hold never captured money even if an unpaid intent id lingers,
        // so the template must not report a retained payment.
        $paymentCollected = ! $holdLapsed
            && $this->context !== ReservationCancelledEvent::CONTEXT_UNPAID_HOLD
            && $this->reservation->hasGatewayPayment();

        $this->with([
            'holdLapsed' => $holdLapsed,
            'paymentCollected' => $paymentCollected,
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.cancelled'));
    }
}

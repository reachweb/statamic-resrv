<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Reservation;

class ReservationPaymentRequest extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
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
        $this->with([
            // Fresh Price: add() mutates in place and Eloquent caches cast instances.
            'amountDue' => Price::create($this->reservation->payment->format())
                ->add($this->reservation->payment_surcharge)
                ->format(),
            'payUrl' => $this->reservation->customerPaymentUrl(),
            'isOffline' => $this->gatewayIsOffline(),
        ]);

        $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.payment-request'));

        $this->dispatchBuildingEvent($this->reservation);

        return $this;
    }

    protected function gatewayIsOffline(): bool
    {
        try {
            return $this->reservation->resolvePaymentGateway()->supportsManualConfirmation();
        } catch (UnknownPaymentGateway) {
            return false;
        }
    }
}

<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;

/** Tells the admins that a website booking paid offline is waiting for its payment. */
class ReservationAwaitingPayment extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;

        $this->subject(__('Reservation #:id awaiting payment [:date] [:email]', [
            'id' => $reservation->id,
            'date' => $reservation->date_start->format('Y-m-d'),
            'email' => $reservation->customer?->email ?: 'unknown',
        ]));
    }

    public function build()
    {
        $this->with([
            'paymentMethod' => app(PaymentGatewayManager::class)->label((string) $this->reservation->payment_gateway),
            'reservationUrl' => cp_route('resrv.reservation.show', $this->reservation->id),
        ]);

        return $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.awaiting-payment'));
    }
}

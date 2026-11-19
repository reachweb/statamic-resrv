<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationAwaitingPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;
use Reach\StatamicResrv\Traits\IsolatesDeferredSendFailures;

/**
 * A checkout booking placed through an offline gateway: the customer gets the payment request with
 * the payment instructions, and the admins are told a booking is waiting for its payment.
 */
class SendAwaitingPaymentEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, IsolatesDeferredSendFailures, Queueable, SerializesModels;

    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    public function handle(): void
    {
        // The sender refuses a request that can no longer be paid, such as a booking an admin marked as
        // paid in the meantime, and the mail transport can fail. Either skips the customer's email only:
        // the admins must still hear of a booking that holds its dates until it is paid.
        try {
            // It is the customer's only email about the booking, so a disabled event must not go unnoticed.
            if (! app(ManualReservationCreator::class)->sendPaymentRequestEmail($this->reservation)) {
                Log::warning('The payment request email of a new offline booking was not sent: the customer_payment_request email event is disabled or has no recipients.', [
                    'reservation_id' => $this->reservation->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('The payment request email of a new offline booking was not sent.', [
                'reservation_id' => $this->reservation->id,
                'error' => $e->getMessage(),
            ]);
        }

        app(ReservationEmailDispatcher::class)->send(
            $this->reservation,
            ReservationEmailEvent::AdminAwaitingPayment,
            new ReservationAwaitingPayment($this->reservation),
        );
    }
}

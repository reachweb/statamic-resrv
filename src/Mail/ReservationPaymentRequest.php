<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationPaymentRequest extends Mailable
{
    use Queueable, SerializesModels;

    public $reservation;

    /** Whether an earlier request already reached the customer; captured before this send stamps it. */
    public bool $reminder;

    /** Whether this send follows an Extend hold — only then does the reminder announce a new deadline. */
    public bool $deadlineChanged;

    public function __construct(Reservation $reservation, ?bool $reminder = null, bool $deadlineChanged = false)
    {
        $this->reservation = $reservation;
        $this->reminder = $reminder ?? $reservation->payment_request_email_sent_at !== null;
        $this->deadlineChanged = $deadlineChanged;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $isOffline = $this->reservation->paymentGatewaySupportsManualConfirmation();
        $dueAt = $this->reservation->paymentDueAt();
        $holdExpiresAt = $this->reservation->hold_expires_at;

        $this->with([
            'amountDue' => $this->reservation->amountDue()->format(),
            'payUrl' => $this->reservation->customerPaymentUrl(),
            'isOffline' => $isOffline,
            'offlineInstructions' => $isOffline ? $this->offlineInstructions() : null,
            'customerFirstName' => $this->customerFirstName(),
            'isReminder' => $this->reminder,
            // A booking the customer made at checkout, rather than one an admin created for them.
            'placedByCustomer' => $this->reservation->created_by === null,
            'deadlineChanged' => $this->deadlineChanged,
            'paymentDueBy' => $dueAt?->format('d-m-Y H:i T'),
            // The arrival day closes payment before a later hold deadline does.
            'paymentDueIsArrival' => $dueAt !== null && $holdExpiresAt !== null && $dueAt->lt($holdExpiresAt),
        ]);

        $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.payment-request'));

        $this->dispatchBuildingEvent($this->reservation);

        return $this;
    }

    protected function customerFirstName(): ?string
    {
        $firstName = $this->reservation->customerData->get('first_name');

        return is_string($firstName) && trim($firstName) !== '' ? trim($firstName) : null;
    }
}

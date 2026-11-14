<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;
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
            'deadlineChanged' => $this->deadlineChanged,
            'paymentDueBy' => $dueAt?->format('d-m-Y H:i T'),
            // The arrival day closes payment before a later hold deadline does.
            'paymentDueIsArrival' => $dueAt !== null && $holdExpiresAt !== null && $dueAt->lt($holdExpiresAt),
        ]);

        $this->markdown($this->markdownTemplate('statamic-resrv::email.reservations.payment-request'));

        $this->dispatchBuildingEvent($this->reservation);

        return $this;
    }

    /**
     * The offline instructions setting as literal text, like the pay page. The <div> makes the
     * markdown renderer pass it through as one HTML block (no list/emphasis/link parsing); every
     * break is "<br>\n", so no blank line ends that block and the plain-text part keeps its lines.
     */
    protected function offlineInstructions(): ?HtmlString
    {
        $instructions = config('resrv-config.manual_reservations_offline_instructions');

        if (! is_string($instructions) || trim($instructions) === '') {
            return null;
        }

        return new HtmlString('<div>'.preg_replace('/\R/', "<br>\n", e(trim($instructions))).'</div>');
    }

    protected function customerFirstName(): ?string
    {
        $firstName = $this->reservation->customerData->get('first_name');

        return is_string($firstName) && trim($firstName) !== '' ? trim($firstName) : null;
    }
}

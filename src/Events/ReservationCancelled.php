<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationCancelled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** The cancellation came from the hold-lapse sweep: the customer never paid in time. */
    public const CONTEXT_HOLD_LAPSED = 'hold_lapsed';

    /**
     * An admin cancelled an awaiting-payment (manual) reservation. No money was ever
     * captured, even if an unpaid (now-voided) intent id lingers on the row.
     */
    public const CONTEXT_UNPAID_HOLD = 'unpaid_hold';

    public $reservation;

    /** @param  ?string  $context  Why the booking was cancelled (CONTEXT_*) for email wording; null for the existing flows. */
    public function __construct(Reservation $reservation, public ?string $context = null)
    {
        $this->reservation = $reservation;
    }
}

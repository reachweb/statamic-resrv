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

    public $reservation;

    /**
     * @param  ?string  $context  Why the booking was cancelled (CONTEXT_* constants), so the
     *                            notification emails can adjust their wording. Null for the
     *                            existing customer/CP cancellation flows.
     */
    public function __construct(Reservation $reservation, public ?string $context = null)
    {
        $this->reservation = $reservation;
    }
}

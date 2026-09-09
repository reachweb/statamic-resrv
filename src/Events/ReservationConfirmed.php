<?php

namespace Reach\StatamicResrv\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Models\Reservation;

class ReservationConfirmed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const VIA_CHECKOUT = 'checkout';

    public const VIA_WEBHOOK = 'webhook';

    public const VIA_CP = 'cp';

    public $reservation;

    public $via;

    public $payment;

    /**
     * @param  string  $via  Which flow confirmed the reservation (VIA_CHECKOUT, VIA_WEBHOOK or VIA_CP).
     * @param  array  $payment  Gateway payment facts (gateway, payment_id) when a webhook confirmed.
     */
    public function __construct(Reservation $reservation, string $via = self::VIA_CHECKOUT, array $payment = [])
    {
        $this->reservation = $reservation;
        $this->via = $via;
        $this->payment = $payment;
    }
}

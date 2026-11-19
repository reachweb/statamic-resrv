<?php

namespace Reach\StatamicResrv\Enums;

/**
 * How a booking's money was settled, written with the transition that settles it. The status says
 * where the booking is; this says whether its money arrived and how, and it stays after a refund or
 * cancellation, so displays, refunds and reports read it instead of reconstructing it.
 */
enum PaymentSettlement: string
{
    /** Nothing was collected: a booking still unpaid, or a partner or zero-payment one. */
    case None = 'none';

    /** Paid by the gateway charge payment_id names. */
    case Gateway = 'gateway';

    /**
     * Paid outside any gateway: an admin marked it as paid. A payment_id left on the row is an
     * online payment the customer started, kept because it may have charged too.
     */
    case OutOfBand = 'out_of_band';

    /**
     * Unknown: a booking cancelled unpaid whose online payment may have charged before the
     * cancellation, or an offline checkout booking confirmed before v6.6.0, when the checkout
     * confirmed it before any money arrived.
     */
    case Unresolved = 'unresolved';

    /** Whether the booking's money is known to have arrived. */
    public function received(): bool
    {
        return $this === self::Gateway || $this === self::OutOfBand;
    }
}

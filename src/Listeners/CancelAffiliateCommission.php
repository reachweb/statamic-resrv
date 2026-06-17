<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Events\ReservationRefunded;

class CancelAffiliateCommission
{
    /**
     * Mark the refunded reservation's affiliate commission as cancelled so payout reporting
     * stops counting it, while keeping the pivot row for audit. A refund here is always a full
     * refund (the gateway returns the whole charge, or a partner/zero-payment booking is voided),
     * so the booking earns no commission.
     *
     * Stamps only rows whose cancelled_at is still null, so re-dispatching the event leaves the
     * original cancellation timestamp untouched and never errors.
     */
    public function handle(ReservationRefunded $event): void
    {
        DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $event->reservation->id)
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }
}

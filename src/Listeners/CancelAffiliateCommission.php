<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationRefunded;

class CancelAffiliateCommission
{
    /**
     * Mark the reservation's affiliate commission as cancelled so payout reporting stops
     * counting it, while keeping the pivot row for audit. The rule follows the money: a
     * commission is owed only while the business retains revenue for the booking.
     *
     * - ReservationRefunded: the gateway returned the whole charge — always void.
     * - ReservationCancelled: void only when the business holds no revenue for the booking
     *   (partner / zero-charge voids, unpaid manual holds). A no-refund cancellation of a
     *   paid booking keeps the payment, so the commission stands.
     *
     * Stamps only rows whose cancelled_at is still null, so re-dispatching the event leaves the
     * original cancellation timestamp untouched and never errors.
     */
    public function handle(ReservationRefunded|ReservationCancelled $event): void
    {
        if ($event instanceof ReservationCancelled && $this->businessRetainsRevenue($event)) {
            return;
        }

        DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $event->reservation->id)
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }

    /**
     * An unpaid-hold cancellation never captured money even when an unpaid intent id lingers
     * in payment_id — hasGatewayPayment() alone would misread that id as revenue. An in-flight
     * capture will be refunded at the gateway, so no revenue is retained there either.
     */
    protected function businessRetainsRevenue(ReservationCancelled $event): bool
    {
        if (in_array($event->context, [
            ReservationCancelled::CONTEXT_UNPAID_HOLD,
            ReservationCancelled::CONTEXT_HOLD_LAPSED,
            ReservationCancelled::CONTEXT_PAYMENT_IN_FLIGHT,
        ], true)) {
            return false;
        }

        return $event->reservation->hasGatewayPayment();
    }
}

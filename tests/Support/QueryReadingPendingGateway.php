<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Models\Reservation;

/**
 * Like UncheckedPendingGateway, but reads the id from the query string only. A request body can
 * then carry a different `payment_pending` than the one this gateway looks up.
 */
class QueryReadingPendingGateway extends UncheckedPendingGateway
{
    public function name(): string
    {
        return 'queryreader';
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->query('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->query('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }
}

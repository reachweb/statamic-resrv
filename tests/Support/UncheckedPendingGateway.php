<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Third-party gateway double that still resolves `payment_pending` the way the upgrade guide
 * documented before Step 15: straight from the query string, with no ownership check.
 */
class UncheckedPendingGateway extends FakePaymentGateway
{
    public function name(): string
    {
        return 'unchecked';
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }
}

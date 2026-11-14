<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

/**
 * Resolves the checkout-complete page's `?payment_pending=<id>` query parameter.
 *
 * The id is visitor input and reservation ids are sequential, so reservation data is returned
 * only to the browser session that made the booking — never for an id taken from the URL alone.
 */
class PendingPayment
{
    /** The reservation being checked out, set by AddReservationIdToSession. */
    protected const CHECKOUT_KEY = 'resrv_reservation';

    /** The reservation this session last brought to the checkout-complete page. */
    protected const COMPLETED_KEY = 'resrv_completed_reservation';

    /**
     * The whole body of a gateway's handlePaymentPending(): false when the request carries no
     * `payment_pending`, otherwise the pending payload. Its reservation data is empty unless
     * the id belongs to the session.
     */
    public static function fromRequest(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $id = request()->input('payment_pending');

        $reservation = static::ownedBySession($id) ? Reservation::find($id) : null;

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    /**
     * Strip the reservation from a gateway's handleRedirectBack() result when the request names
     * a `payment_pending` id the session does not own. Covers gateways whose
     * handlePaymentPending() still looks the id up unchecked.
     */
    public static function guard(array $redirectData): array
    {
        if (request()->has('payment_pending') && ! static::ownedBySession(request()->input('payment_pending'))) {
            $redirectData['reservation'] = [];
        }

        return $redirectData;
    }

    /**
     * End the checkout session, remembering its reservation so a refresh of the
     * checkout-complete page still resolves the customer's own `payment_pending` id.
     */
    public static function endCheckout(): void
    {
        if ($id = session()->pull(self::CHECKOUT_KEY)) {
            session([self::COMPLETED_KEY => $id]);
        }
    }

    protected static function ownedBySession(mixed $id): bool
    {
        // An array (`payment_pending[]=1`) would make Reservation::find() return several rows.
        if (! is_scalar($id) || (string) $id === '') {
            return false;
        }

        return collect([session(self::CHECKOUT_KEY), session(self::COMPLETED_KEY)])
            ->filter()
            ->contains(fn ($owned) => (string) $owned === (string) $id);
    }
}

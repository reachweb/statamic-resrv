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

    /** Reservations this session brought to the checkout-complete page: [id => Unix time]. */
    protected const COMPLETED_KEY = 'resrv_completed_reservations';

    /**
     * How long a completed reservation stays readable from its checkout-complete URL. Long enough
     * for a refresh or a second tab, short enough that a later user of a shared browser cannot
     * reopen the page from its history.
     */
    protected const COMPLETED_MINUTES = 60;

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
     * a `payment_pending` and the reservation that came back is not the session's own. Covers
     * gateways whose handlePaymentPending() still looks the id up unchecked. The returned row is
     * what gets checked, not the request value: a gateway may read the id from the query string
     * while a request body carries a different one.
     */
    public static function guard(array $redirectData): array
    {
        if (! static::requestNamesPendingPayment()) {
            return $redirectData;
        }

        $reservation = $redirectData['reservation'] ?? [];

        if (! is_array($reservation) || ! static::ownedBySession($reservation['id'] ?? null)) {
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
        $completed = static::completedReservations();

        if ($id = session()->pull(self::CHECKOUT_KEY)) {
            $completed[(string) $id] = now()->getTimestamp();
        }

        if ($completed === []) {
            session()->forget(self::COMPLETED_KEY);

            return;
        }

        session([self::COMPLETED_KEY => $completed]);
    }

    /** `payment_pending` anywhere a gateway could read it: merged input, query string or body. */
    protected static function requestNamesPendingPayment(): bool
    {
        $request = request();

        return $request->has('payment_pending')
            || $request->query->has('payment_pending')
            || $request->request->has('payment_pending');
    }

    protected static function ownedBySession(mixed $id): bool
    {
        // An array (`payment_pending[]=1`) would make Reservation::find() return several rows.
        if (! is_scalar($id) || (string) $id === '') {
            return false;
        }

        $id = (string) $id;

        return (string) session(self::CHECKOUT_KEY) === $id
            || array_key_exists($id, static::completedReservations());
    }

    /**
     * The completed reservations still inside their window, as [id => Unix time].
     *
     * @return array<string, int>
     */
    protected static function completedReservations(): array
    {
        $completed = session(self::COMPLETED_KEY);

        if (! is_array($completed)) {
            return [];
        }

        $cutoff = now()->subMinutes(self::COMPLETED_MINUTES)->getTimestamp();

        return array_filter($completed, fn ($completedAt) => is_int($completedAt) && $completedAt >= $cutoff);
    }
}

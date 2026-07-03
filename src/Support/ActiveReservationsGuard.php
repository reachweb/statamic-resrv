<?php

namespace Reach\StatamicResrv\Support;

use Carbon\Carbon;
use Closure;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;

final class ActiveReservationsGuard
{
    public static function hasActiveReservationsForEntry(string $statamicId): bool
    {
        // item_id lives only on the parent Reservation; a ChildReservation is only "active"
        // if its parent is, so the parent check is sufficient at the entry level.
        return Reservation::where('item_id', $statamicId)
            ->whereNotIn('status', ReservationStatus::terminal())
            ->exists();
    }

    /**
     * Whether in-flight checkouts (pending/webhook) overlap the range. These are the only
     * holds that may release +quantity asynchronously (on expiry), which would corrupt an
     * absolute CP `available` edit. Confirmed/partner bookings keep their hold key for
     * life but only release on an explicit refund — that restores stock on top of whatever
     * the admin set, so they must not block inventory edits.
     */
    public static function hasInFlightReservationsForRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds = null,
    ): bool {
        return self::reservationsOverlapRange(
            $statamicId,
            $dateStart,
            $dateEnd,
            $rateIds,
            fn ($query) => $query->whereIn('status', ReservationStatus::inFlight()),
        );
    }

    /**
     * Whether any non-terminal reservation (including confirmed/partner bookings) overlaps
     * the range. Destructive operations must block on these too: deleting availability rows
     * would orphan the hold keys of real bookings, so a later refund's removeFromPending
     * would find nothing to restore.
     */
    public static function hasActiveReservationsForRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds = null,
    ): bool {
        return self::reservationsOverlapRange(
            $statamicId,
            $dateStart,
            $dateEnd,
            $rateIds,
            fn ($query) => $query->whereNotIn('status', ReservationStatus::terminal()),
        );
    }

    /**
     * Admin CP date ranges treat date_end inclusively; reservation date_end is exclusive.
     * Bump the admin's end by one day so the overlap check uses consistent half-open semantics.
     */
    private static function reservationsOverlapRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds,
        Closure $statusConstraint,
    ): bool {
        $exclusiveEnd = Carbon::parse($dateEnd)->addDay()->toDateString();

        $hasParent = Reservation::where('item_id', $statamicId)
            ->where($statusConstraint)
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->exists();

        if ($hasParent) {
            return true;
        }

        return ChildReservation::whereHas('parent', function ($q) use ($statamicId, $statusConstraint) {
            $q->where('item_id', $statamicId)->where($statusConstraint);
        })
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->exists();
    }
}

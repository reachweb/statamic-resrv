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
     * Whether in-flight checkouts (pending/webhook) overlap the range. These are the only holds
     * that may release +quantity asynchronously (on expiry), which would corrupt an absolute CP
     * `available` edit. Restricted to affects_availability holds — view-only holds never restore
     * stock, so they must not block edits. Confirmed/partner bookings and awaiting-payment manual
     * holds keep their hold key until an explicit refund/cancel (or a hold lapse), which restores
     * stock on top of whatever the admin set — so they must not block inventory edits.
     */
    public static function hasInFlightReservationsForRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds = null,
    ): bool {
        return self::reservationsOverlapRange($statamicId, $dateStart, $dateEnd, $rateIds, self::inFlightConstraint());
    }

    /**
     * The references of the in-flight checkouts blocking an inventory edit on the range, so the
     * CP error can name them.
     *
     * @return list<string>
     */
    public static function inFlightReservationReferencesForRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds = null,
    ): array {
        return self::overlappingReservationReferences(
            $statamicId,
            $dateStart,
            $dateEnd,
            $rateIds,
            self::inFlightConstraint(),
        );
    }

    private static function inFlightConstraint(): Closure
    {
        return fn ($query) => $query->whereIn('status', ReservationStatus::inFlight())
            ->where('affects_availability', true);
    }

    /**
     * Whether any non-terminal reservation (including confirmed/partner bookings) overlaps
     * the range. Destructive operations must block on these too: deleting availability rows
     * would orphan the hold keys of real bookings, so a later refund's removeFromPending
     * would find nothing to restore. View-only holds (affects_availability=false) never
     * wrote a hold key, so there is nothing to orphan — they must not block deletion.
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
            fn ($query) => $query->whereNotIn('status', ReservationStatus::terminal())
                ->where('affects_availability', true),
        );
    }

    /**
     * Same half-open overlap as reservationsOverlapRange(), returning the parent references
     * (a child row's blocking booking is its parent).
     *
     * @return list<string>
     */
    private static function overlappingReservationReferences(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds,
        Closure $statusConstraint,
    ): array {
        $exclusiveEnd = Carbon::parse($dateEnd)->addDay()->toDateString();

        $parents = Reservation::where('item_id', $statamicId)
            ->where($statusConstraint)
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->pluck('reference');

        $childParents = Reservation::whereIn('id', ChildReservation::whereHas('parent', function ($q) use ($statamicId, $statusConstraint) {
            $q->where('item_id', $statamicId)->where($statusConstraint);
        })
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->select('reservation_id'))
            ->pluck('reference');

        return $parents->merge($childParents)->unique()->values()->all();
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

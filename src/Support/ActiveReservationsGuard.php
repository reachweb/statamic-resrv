<?php

namespace Reach\StatamicResrv\Support;

use Carbon\Carbon;
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
     * Admin CP date ranges treat date_end inclusively; reservation date_end is exclusive.
     * Bump the admin's end by one day so the overlap check uses consistent half-open semantics.
     */
    public static function hasActiveReservationsForRange(
        string $statamicId,
        string $dateStart,
        string $dateEnd,
        ?array $rateIds = null,
    ): bool {
        $exclusiveEnd = Carbon::parse($dateEnd)->addDay()->toDateString();

        $hasParent = Reservation::where('item_id', $statamicId)
            ->whereNotIn('status', ReservationStatus::terminal())
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->exists();

        if ($hasParent) {
            return true;
        }

        return ChildReservation::whereHas('parent', function ($q) use ($statamicId) {
                $q->where('item_id', $statamicId)
                    ->whereNotIn('status', ReservationStatus::terminal());
            })
            ->where('date_start', '<', $exclusiveEnd)
            ->where('date_end', '>', $dateStart)
            ->when($rateIds, fn ($q, $ids) => $q->whereIn('rate_id', $ids))
            ->exists();
    }
}

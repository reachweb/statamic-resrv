<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\AvailabilityChangeReason;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Models\Availability;

class IncreaseAvailability
{
    public function __construct(protected Availability $availability) {}

    public function handle($event): void
    {
        // Mirrors the DecreaseAvailability guard: a reservation that never decremented
        // stock must never restore it, whichever event (expiry/cancel/refund) fires.
        if (! $event->reservation->affects_availability) {
            return;
        }

        $reason = $this->reasonForEvent($event);

        if ($event->reservation->type === 'parent') {
            $this->incrementMultiple($event, $reason);
        } else {
            $this->availability->incrementAvailability(
                date_start: $event->reservation->date_start,
                date_end: $event->reservation->date_end,
                quantity: $event->reservation->quantity,
                statamic_id: $event->reservation->item_id,
                reservationId: $event->reservation->id,
                rateId: $event->reservation->rate_id,
                reason: $reason,
            );
        }
    }

    protected function incrementMultiple($event, ?AvailabilityChangeReason $reason): void
    {
        $childs = $event->reservation->childs;
        $childs->each(function ($child) use ($event, $reason) {
            // parentReservationId: child ids live in their own sequence — the activity log
            // must record the parent booking id, which is what the CP links and filters use.
            $this->availability->incrementAvailability(
                date_start: $child->date_start,
                date_end: $child->date_end,
                quantity: $child->quantity,
                statamic_id: $event->reservation->item_id,
                reservationId: $child->id,
                rateId: $child->rate_id,
                isChildReservation: true,
                reason: $reason,
                parentReservationId: $event->reservation->id,
            );
        });
    }

    protected function reasonForEvent($event): ?AvailabilityChangeReason
    {
        return match (true) {
            $event instanceof ReservationExpired => AvailabilityChangeReason::ReservationExpired,
            $event instanceof ReservationCancelled => AvailabilityChangeReason::ReservationCancelled,
            $event instanceof ReservationRefunded => AvailabilityChangeReason::ReservationRefunded,
            default => null,
        };
    }
}

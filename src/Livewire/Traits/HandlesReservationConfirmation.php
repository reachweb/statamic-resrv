<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ActivityLog;

trait HandlesReservationConfirmation
{
    /**
     * Confirm a reservation while tolerating a concurrent expiry race. Dispatches
     * ReservationConfirmed and returns true on a real transition; if the row changed under
     * the lock, returns true only when another request already reached the target state
     * (a duplicate submit), so the caller surfaces the expired error on false.
     */
    protected function confirmOrAlreadyConfirmed(Reservation $reservation, ReservationStatus $target): bool
    {
        if ($reservation->transitionTo($target, tolerant: true)) {
            app(ActivityLog::class)->logReservation(
                reservation: $reservation,
                from: ReservationStatus::PENDING,
                to: $target,
                reason: ReservationLogReason::CheckoutConfirmed,
            );

            ReservationConfirmed::dispatch($reservation);

            return true;
        }

        return $reservation->refresh()->status === $target->value;
    }
}

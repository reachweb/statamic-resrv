<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;

class DecreaseAvailability
{
    public function __construct(protected Availability $availability) {}

    public function handle(ReservationCreated $event): void
    {
        if ($event->reservation->type === 'parent') {
            $this->decreaseMultiple($event);
        } else {
            $this->availability->decrementAvailability(
                date_start: $event->reservation->date_start,
                date_end: $event->reservation->date_end,
                quantity: $event->reservation->quantity,
                statamic_id: $event->reservation->item_id,
                reservationId: $event->reservation->id,
                advanced: $event->reservation->property,
                rateId: $event->reservation->rate_id,
            );
        }
    }

    protected function decreaseMultiple(ReservationCreated $event): void
    {
        $childs = $event->reservation->childs()->get();
        $childs->each(function ($child) use ($event) {
            $this->availability->decrementAvailability(
                date_start: $child->date_start,
                date_end: $child->date_end,
                quantity: $child->quantity,
                statamic_id: $event->reservation->item_id,
                reservationId: $child->id,
                advanced: $child->property,
                rateId: $child->rate_id,
            );
        });
    }
}

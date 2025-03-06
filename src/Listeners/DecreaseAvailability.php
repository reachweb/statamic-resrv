<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Availability;

class DecreaseAvailability
{
    protected $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    public function handle(ReservationCreated $event)
    {
        if ($event->reservation->isParent()) {
            $this->decreaseMultiple($event);
        } else {
            $this->availability->decrementAvailability(
                date_start: $event->reservation->date_start,
                date_end: $event->reservation->date_end,
                quantity: $event->reservation->quantity,
                statamic_id: $event->reservation->item_id,
                reservationId: $event->reservation->id,
                advanced: $event->reservation->property
            );
        }
    }

    protected function decreaseMultiple(ReservationCreated $event)
    {
        $childs = $event->reservation->childs()->get();
        $childs->each(function ($child) use ($event) {
            $this->availability->decrementAvailability(
                date_start: $child->date_start,
                date_end: $child->date_end,
                quantity: $child->quantity,
                statamic_id: $child->entry->item_id,
                reservationId: $event->reservation->id,
                advanced: $child->property
            );
        });
    }
}

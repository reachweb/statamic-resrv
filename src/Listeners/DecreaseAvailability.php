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
        if ($event->reservation->type === 'parent') {
            $this->decreaseMultiple($event);
        } else {
            $this->availability->decrementAvailability($event->reservation->date_start, $event->reservation->date_end, $event->reservation->quantity, $event->reservation->item_id);
        }
    }

    protected function decreaseMultiple(ReservationCreated $event)
    {
        $childs = $event->reservation->childs()->get();
        $childs->each(function($child) use ($event) {
            $this->availability->decrementAvailability($child->date_start, $child->date_end, $child->quantity, $event->reservation->item_id);
        });
    }
}

<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Models\Availability;

class IncreaseAvailability
{
    protected $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    public function handle($event)
    {
        if ($event->reservation->type === 'parent') {
            $this->incrementMultiple($event);
        } else {
            $this->availability->incrementAvailability($event->reservation->date_start, $event->reservation->date_end, $event->reservation->quantity, $event->reservation->item_id);
        }
    }

    protected function incrementMultiple($event)
    {
        $childs = $event->reservation->childs()->get();
        $childs->each(function($child) use ($event) {
            $this->availability->incrementAvailability($child->date_start, $child->date_end, $child->quantity, $event->reservation->item_id);
        });
    }
}

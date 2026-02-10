<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Models\Availability;

class IncreaseAvailability
{
    public function __construct(protected Availability $availability) {}

    public function handle($event): void
    {
        if ($event->reservation->type === 'parent') {
            $this->incrementMultiple($event);
        } else {
            $this->availability->incrementAvailability(
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

    protected function incrementMultiple($event): void
    {
        $childs = $event->reservation->childs()->get();
        $childs->each(function ($child) use ($event) {
            $this->availability->incrementAvailability(
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

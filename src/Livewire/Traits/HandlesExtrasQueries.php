<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesExtrasQueries
{
    public function getExtrasForEntry()
    {
        $reservation = Reservation::findOrFail($this->reservationId)->only(['date_start', 'date_end', 'quantity', 'property', 'item_id']);

        $extras = Extra::getPriceForDates($reservation);

        $extras->transform(function ($extra) {
            $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

            return $extra;
        });
        
        return $extras;
    }
}

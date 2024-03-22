<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesOptionsQueries
{
    public function getOptionsForEntry()
    {
        $reservation = $this->reservation ?? Reservation::findOrFail($this->reservation->id);

        $options = Option::entry($reservation->item_id)
            ->where('published', true)
            ->with('values')
            ->get();

        if ($reservation->quantity > 1) {
            $options = $options->map(function ($option) use ($reservation) {
                return Option::find($option->id)->valuesPriceForDates($reservation);
            });
        }

        return $options->map(function ($option) {
            return $option->all();
        });
    }
}

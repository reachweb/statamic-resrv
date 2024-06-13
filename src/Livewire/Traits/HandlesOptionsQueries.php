<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesOptionsQueries
{
    public function getOptionsForReservation(): Collection
    {
        $reservation = $this->reservation ?? Reservation::findOrFail($this->reservation->id);

        $options = $this->getOptionsForId($reservation->item_id)->map(function ($option) use ($reservation) {
            return Option::find($option->id)->valuesPriceForDates($reservation);
        });

        return $options;
    }

    public function getOptionsForSearch($data, $entryId): Collection
    {
        $options = $this->getOptionsForId($entryId)->map(function ($option) use ($data) {
            return Option::find($option->id)->valuesPriceForDates($data);
        });

        return $options;
    }

    protected function getOptionsForId($id): Collection
    {
        return Option::entry($id)
            ->where('published', true)
            ->with('values')
            ->get();
    }
}

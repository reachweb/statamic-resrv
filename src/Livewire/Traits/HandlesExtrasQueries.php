<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesExtrasQueries
{
    public function getExtrasForReservation(): Collection
    {
        $reservation = $this->reservation ?? Reservation::findOrFail($this->reservation->id);

        $extras = Extra::getPriceForDates($reservation);

        $extras->transform(function ($extra) {
            $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

            return $extra;
        });

        $this->handleExtrasConditions($extras);

        return $extras;
    }

    public function getExtrasForSearch($data, $entryId): Collection
    {
        $extras = Extra::getPriceForDates(array_merge($data, ['item_id' => $entryId]));

        $extras->transform(function ($extra) {
            $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

            return $extra;
        });

        $this->handleExtrasConditions($extras);

        return $extras;
    }

    public function updateEnabledExtraPrices()
    {
        $this->enabledExtras->extras->transform(function ($extra) {
            $extra['price'] = $this->extras->where('id', $extra['id'])->first()->price;

            return $extra;
        });
    }

    public function handleExtrasConditions($extras)
    {
        $extras = collect($extras)->filter(fn ($extra) => count($extra->conditions) > 0);
        $data = $this instanceof Checkout ? $this->reservation : $this->data;

        if ($extras->count() > 0) {
            $this->extraConditions = app(ExtraCondition::class)->calculateConditionArrays($extras, $this->enabledExtras, $data);
            $this->dispatch('extra-conditions-changed', $this->extraConditions);
        }
    }
}

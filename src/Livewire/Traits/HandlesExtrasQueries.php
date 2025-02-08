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
            $extra['price'] = $this->extras->where('id', $extra['id'])->first()->price->format();

            return $extra;
        });
    }

    public function handleExtrasConditions($extras)
    {
        $current = $this->extraConditions;
        $extras = collect($extras)->filter(fn ($extra) => count($extra->conditions) > 0);
        $data = $this instanceof Checkout ? $this->reservation : $this->data;

        if ($extras->count() > 0) {
            $this->extraConditions = app(ExtraCondition::class)->calculateConditionArrays($extras, $this->enabledExtras, $data);
            // Only fire the event if the conditions changed
            if ($this->extraConditions->get('hide') !== $current->get('hide', collect()
                && $this->extraConditions->get('required') !== $current->get('required', collect()))) {
                $this->dispatch('extra-conditions-changed', $this->extraConditions);
            }
        }
    }

    private function createExtraCategoryObject(Collection $items): \stdClass
    {
        $extra = $items->first();
        $category = new \stdClass;
        $category->id = $extra->category?->id ?? null;
        $category->name = $extra->category?->name ?? 'Uncategorized';
        $category->slug = $extra->category?->slug ?? 'uncategorized';
        $category->description = $extra->category?->description ?? null;
        $category->order = $extra->category?->order ?? 9999;
        $category->published = $extra->category?->published ?? true;
        $category->extras = $items;

        return $category;
    }
}

<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
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

        $this->extraConditions = $this->handleExtrasConditions($extras, $this->extraConditions, $this->enabledExtras);

        return $extras;
    }

    public function getExtrasForParentReservation(): Collection
    {
        $allExtras = collect();
        
        $this->reservation->childs->each(function ($child) use ($allExtras) {
            $data = Arr::only($child->toArray(), ['date_start', 'date_end', 'quantity', 'property']);

            $extras = Extra::getPriceForDates(array_merge($data, ['item_id' => $child->entry->item_id]));

            $extras = $extras->transform(function ($extra) {
                $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

                return $extra;
            });

            $extraConditions = $this->handleExtrasConditions($extras, $this->data->getExtraConditions($child->id), $this->data->getEnabledExtras($child->id), $data);
            $this->data->setExtraConditions($child->id, $extraConditions);
            
            $allExtras->put($child->id, $extras);
        });

        return $allExtras;
    }

    public function getExtrasForSearch($data, $entryId): Collection
    {
        $extras = Extra::getPriceForDates(array_merge($data, ['item_id' => $entryId]));

        $extras->transform(function ($extra) {
            $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

            return $extra;
        });

        $this->extraConditions = $this->handleExtrasConditions($extras, $this->extraConditions, $this->enabledExtras, $data);

        return $extras;
    }

    public function updateEnabledExtraPrices()
    {
        $this->enabledExtras->extras->transform(function ($extra) {
            $extra['price'] = $this->extras->where('id', $extra['id'])->first()->price->format();

            return $extra;
        });
    }

    public function handleExtrasConditions($extras, Collection $extraConditions, EnabledExtras $enabledExtras, ?array $data = null)
    {
        $current = $extraConditions;
        $extras = collect($extras)->filter(fn ($extra) => count($extra->conditions) > 0);

        if (! $data) {
            $data = $this instanceof Checkout ? $this->reservation : $this->data;
        }

        if ($extras->count() > 0) {
            $newConditions = app(ExtraCondition::class)->calculateConditionArrays($extras, $enabledExtras, $data);
            // Only fire the event if the conditions changed
            if ($newConditions->get('hide') !== $current->get('hide', collect()
                && $newConditions->get('required') !== $current->get('required', collect()))) {
                $this->dispatch('extra-conditions-changed', $newConditions);
            }
            $current = $newConditions;
        }

        return $current;
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

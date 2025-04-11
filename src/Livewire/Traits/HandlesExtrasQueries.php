<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
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

    public function updateEnabledExtraPrices(): void
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
        $data = isset($this->reservation) ? $this->reservation : $this->data;

        if ($extras->count() > 0) {
            $this->extraConditions = app(ExtraCondition::class)->calculateConditionArrays($extras, $this->enabledExtras, $data);
            // Check if either hide or required conditions changed
            if ($this->conditionsHaveChanged($this->extraConditions->get('hide'), $current->get('hide')) ||
                $this->conditionsHaveChanged($this->extraConditions->get('required'), $current->get('required'))) {
                $this->dispatch('extra-conditions-changed', $current);
            }
        }
    }

    private function conditionsHaveChanged($new, $old)
    {
        $new = $new ?? collect();
        $old = $old ?? collect();

        // If counts differ, they're definitely different
        if ($new->count() !== $old->count()) {
            return true;
        }

        // If both empty, they're the same
        if ($new->isEmpty() && $old->isEmpty()) {
            return false;
        }

        // Compare keys and values
        foreach ($new as $key => $value) {
            if (! $old->has($key) || $old[$key] !== $value) {
                return true;
            }
        }

        return false;
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

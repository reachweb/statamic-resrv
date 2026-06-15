<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\AvailabilityMultiResults;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesExtrasQueries
{
    public function getExtrasForReservation(): Collection
    {
        $reservation = $this->reservation ?? Reservation::findOrFail($this->reservation->id);

        $extras = $reservation->isParent()
            ? $this->getExtrasWithParentPricing($reservation)
            : Extra::getPriceForDates($reservation);

        $this->loadConditionsAsCollection($extras);

        $this->handleExtrasConditions($extras);

        return $extras;
    }

    /**
     * Server-priced extras for the reservation WITHOUT the conditions machinery. Used at checkout to
     * rebuild the pivot snapshot from authoritative prices (see EnabledExtras::extrasToSync). Mirrors
     * getExtrasForReservation()'s pricing branch but skips loadConditionsAsCollection/
     * handleExtrasConditions, which reference host-component state (extraConditions/extras/data) that
     * Checkout does not declare.
     */
    protected function getExtrasPricedForReservation(): Collection
    {
        return $this->reservation->isParent()
            ? $this->getExtrasWithParentPricing($this->reservation)
            : Extra::getPriceForDates($this->reservation);
    }

    protected function getExtrasWithParentPricing(Reservation $reservation): Collection
    {
        $firstChild = $reservation->childs->first();

        $extras = Extra::getPriceForDates([
            'item_id' => $reservation->item_id,
            'date_start' => $firstChild->date_start,
            'date_end' => $firstChild->date_end,
            'quantity' => 1,
        ]);

        return $extras->transform(function ($extra) use ($reservation) {
            $totalPrice = Price::create(0);

            foreach ($reservation->childs as $child) {
                $childData = [
                    'date_start' => $child->date_start,
                    'date_end' => $child->date_end,
                    'quantity' => $child->quantity,
                    'item_id' => $reservation->item_id,
                    'rate_id' => $child->rate_id,
                    // Custom-priced extras need customerData to match the validation path; without it they diverge.
                    'customer' => $reservation->customerData,
                ];

                // Fresh instance required: Extra::priceForDates mutates $this->price via dynamic pricing
                $freshExtra = Extra::find($extra->id);
                $totalPrice->add(Price::create($freshExtra->priceForDates($childData)));
            }

            $extra->price = $totalPrice->format();

            return $extra;
        });
    }

    public function getExtrasForSearch($data, $entryId): Collection
    {
        $extras = Extra::getPriceForDates(array_merge($data, ['item_id' => $entryId]));

        $this->loadConditionsAsCollection($extras);

        $this->handleExtrasConditions($extras);

        return $extras;
    }

    /**
     * Aggregate available-extra prices across the multi-result cart selections so the
     * displayed prices reflect the whole cart, not just the most recent search. Mirrors
     * the parent-reservation pricing path used during checkout.
     *
     * @param  array<int, array{date_start: string, date_end: string, quantity: int, rate_id: int}>  $selections
     */
    public function getExtrasForSelections(array $selections, string $entryId): Collection
    {
        $first = $selections[0];

        // Seed the available extras list with one selection's worth of data, then
        // overwrite each extra's price with the across-selections aggregate.
        $extras = Extra::getPriceForDates([
            'item_id' => $entryId,
            'date_start' => $first['date_start'],
            'date_end' => $first['date_end'],
            'quantity' => 1,
        ]);

        $extras->transform(function ($extra) use ($selections, $entryId) {
            $totalPrice = Price::create(0);

            foreach ($selections as $selection) {
                $selectionData = [
                    'item_id' => $entryId,
                    'date_start' => $selection['date_start'],
                    'date_end' => $selection['date_end'],
                    'quantity' => $selection['quantity'],
                    'rate_id' => $selection['rate_id'],
                ];

                // Fresh instance required: Extra::priceForDates mutates $this->price via dynamic pricing
                $freshExtra = Extra::find($extra->id);
                $totalPrice->add(Price::create($freshExtra->priceForDates($selectionData)));
            }

            $extra->price = $totalPrice->format();

            return $extra;
        });

        $this->loadConditionsAsCollection($extras);

        $this->handleExtrasConditions($extras);

        return $extras;
    }

    /**
     * Fetch every extra's conditions in a single query instead of a find() + conditions()->get()
     * per extra (the N+1 this replaces). conditions() is declared hasOne, but an extra can hold
     * multiple condition rows (extra_id is a plain index, not unique), so group all rows by extra_id
     * and expose each set as the Collection downstream code counts/iterates.
     */
    protected function loadConditionsAsCollection(Collection $extras): void
    {
        $conditionsByExtra = ExtraCondition::whereIn('extra_id', $extras->pluck('id'))
            ->get()
            ->groupBy('extra_id');

        $extras->each(function ($extra) use ($conditionsByExtra) {
            $extra->setRelation('conditions', $conditionsByExtra->get($extra->id, collect()));
        });
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

        if ($extras->count() > 0) {
            if (isset($this->reservation) && $this->reservation->isParent()) {
                $this->extraConditions = $this->evaluateConditionsForParentReservation($extras);
            } elseif (! isset($this->reservation) && ($selections = $this->getMultiSelectionsFromSession())) {
                $this->extraConditions = $this->evaluateConditionsForSelections($extras, $selections);
            } else {
                $data = isset($this->reservation) ? $this->reservation : $this->data;
                $this->extraConditions = app(ExtraCondition::class)->calculateConditionArrays($extras, $this->enabledExtras, $data);
            }

            if ($this->conditionsHaveChanged($this->extraConditions->get('hide'), $current->get('hide')) ||
                $this->conditionsHaveChanged($this->extraConditions->get('required'), $current->get('required'))) {
                $this->dispatch('extra-conditions-changed', $current);
            }
        }
    }

    /**
     * Multi-cart selections are stored in a shared session key. They are only
     * relevant when the parent component explicitly opts in via
     * $useMultiSelections (i.e., it is the multi-results page), AND the cart
     * actually belongs to this component's entry. Without these guards, an
     * in-progress cart for entry A would hijack the standard
     * availability-results page for entry A — see Extras::extras() and
     * handleExtrasConditions() — and a stale cart from entry A would leak
     * into pages for unrelated entries in the same session.
     *
     * @return ?array<int, array{date_start: string, date_end: string, quantity: int, rate_id: int}>
     */
    protected function getMultiSelectionsFromSession(): ?array
    {
        // property_exists guard: this trait is also used by Checkout, which
        // does not declare $useMultiSelections. Touching a typed property that
        // does not exist on the host class would otherwise raise an error.
        if (! property_exists($this, 'useMultiSelections') || ! $this->useMultiSelections) {
            return null;
        }

        $cartOwner = session(AvailabilityMultiResults::CART_OWNER_SESSION_KEY);

        if ($cartOwner === null || $cartOwner !== $this->entryId) {
            return null;
        }

        $selections = session('resrv-multi-selections');

        return ! empty($selections) ? $selections : null;
    }

    /**
     * @param  array<int, array{date_start: string, date_end: string, quantity: int, rate_id: int}>  $selections
     */
    protected function evaluateConditionsForSelections(Collection $extras, array $selections): Collection
    {
        $allRequired = collect();
        $perSelectionHidden = collect();
        $conditionEvaluator = app(ExtraCondition::class);

        foreach ($selections as $selection) {
            $selectionData = [
                'date_start' => $selection['date_start'],
                'date_end' => $selection['date_end'],
                'quantity' => $selection['quantity'],
                'rate_id' => $selection['rate_id'],
            ];

            $selectionConditions = $conditionEvaluator->calculateConditionArrays(
                $extras, $this->enabledExtras, $selectionData
            );

            $allRequired = $allRequired->merge($selectionConditions->get('required', collect()));
            $perSelectionHidden->push($selectionConditions->get('hide', collect()));
        }

        // Hidden only if ALL selections trigger the hide condition
        $hiddenInAll = $perSelectionHidden->first() ?? collect();
        foreach ($perSelectionHidden->skip(1) as $selectionHidden) {
            $hiddenInAll = $hiddenInAll->intersect($selectionHidden);
        }

        return collect([
            'required' => $allRequired->unique()->values(),
            'hide' => $hiddenInAll->values(),
        ]);
    }

    protected function evaluateConditionsForParentReservation(Collection $extras): Collection
    {
        $allRequired = collect();
        $perChildHidden = collect();
        $conditionEvaluator = app(ExtraCondition::class);

        foreach ($this->reservation->childs as $child) {
            $childData = [
                'date_start' => $child->date_start,
                'date_end' => $child->date_end,
                'quantity' => $child->quantity,
                'rate_id' => $child->rate_id,
            ];

            $childConditions = $conditionEvaluator->calculateConditionArrays(
                $extras, $this->enabledExtras, $childData
            );

            $allRequired = $allRequired->merge($childConditions->get('required', collect()));
            $perChildHidden->push($childConditions->get('hide', collect()));
        }

        // Hidden only if ALL children trigger the hide condition
        $hiddenInAll = $perChildHidden->first() ?? collect();
        foreach ($perChildHidden->skip(1) as $childHidden) {
            $hiddenInAll = $hiddenInAll->intersect($childHidden);
        }

        return collect([
            'required' => $allRequired->unique()->values(),
            'hide' => $hiddenInAll->values(),
        ]);
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

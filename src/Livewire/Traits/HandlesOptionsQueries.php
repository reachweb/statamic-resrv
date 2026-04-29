<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Database\Eloquent\Collection;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\AvailabilityMultiResults;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesOptionsQueries
{
    public function getOptionsForReservation(): Collection
    {
        $reservation = $this->reservation ?? Reservation::findOrFail($this->reservation->id);

        if ($reservation->isParent()) {
            return $this->getOptionsWithParentPricing($reservation);
        }

        return $this->getOptionsForId($reservation->item_id)->map(function ($option) use ($reservation) {
            return Option::find($option->id)->valuesPriceForDates($reservation);
        });
    }

    protected function getOptionsWithParentPricing(Reservation $reservation): Collection
    {
        return $this->getOptionsForId($reservation->item_id)->map(function ($option) use ($reservation) {
            $option = Option::find($option->id);

            foreach ($option->values as $value) {
                $value->original_price = $value->price->format();
                $totalPrice = Price::create(0);

                foreach ($reservation->childs as $child) {
                    $childData = [
                        'date_start' => $child->date_start,
                        'date_end' => $child->date_end,
                        'quantity' => $child->quantity,
                        'item_id' => $reservation->item_id,
                        'rate_id' => $child->rate_id,
                    ];

                    $totalPrice->add(Price::create($value->priceForDates($childData)));
                }

                $value->price = $totalPrice->format();
            }

            return $option;
        });
    }

    public function getOptionsForSearch($data, $entryId): Collection
    {
        $options = $this->getOptionsForId($entryId)->map(function ($option) use ($data) {
            return Option::find($option->id)->valuesPriceForDates($data);
        });

        return $options;
    }

    /**
     * Aggregate option-value prices across the multi-result cart selections so the
     * displayed prices reflect the whole cart, not just the most recent search.
     *
     * @param  array<int, array{date_start: string, date_end: string, quantity: int, rate_id: int}>  $selections
     */
    public function getOptionsForSelections(array $selections, string $entryId): Collection
    {
        return $this->getOptionsForId($entryId)->map(function ($option) use ($selections, $entryId) {
            $option = Option::find($option->id);

            foreach ($option->values as $value) {
                $value->original_price = $value->price->format();
                $totalPrice = Price::create(0);

                foreach ($selections as $selection) {
                    $selectionData = [
                        'date_start' => $selection['date_start'],
                        'date_end' => $selection['date_end'],
                        'quantity' => $selection['quantity'],
                        'item_id' => $entryId,
                        'rate_id' => $selection['rate_id'],
                    ];

                    $totalPrice->add(Price::create($value->priceForDates($selectionData)));
                }

                $value->price = $totalPrice->format();
            }

            return $option;
        });
    }

    /**
     * Multi-cart selections are stored in a shared session key. They are only
     * relevant when the parent component explicitly opts in via
     * $useMultiSelections (i.e., it is the multi-results page), AND the cart
     * actually belongs to this component's entry. Without these guards, an
     * in-progress cart for entry A would hijack the standard
     * availability-results page for entry A — see Options::options() — and a
     * stale cart from entry A would leak into pages for unrelated entries in
     * the same session.
     *
     * @return ?array<int, array{date_start: string, date_end: string, quantity: int, rate_id: int}>
     */
    protected function getMultiSelectionsFromSessionForOptions(): ?array
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

    protected function getOptionsForId($id): Collection
    {
        return Option::entry($id)
            ->where('published', true)
            ->with('values')
            ->get();
    }
}

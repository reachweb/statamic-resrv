<?php

namespace Reach\StatamicResrv\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Enums\AddonBlocker;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;

/**
 * Prices an entry's extras and options for one or more stays: a search, a reservation, a parent
 * reservation's children or a multi-results cart. It lists what a customer can pick, prices a
 * selection's lines and totals, and says what blocks booking it as a reason code (AddonBlocker),
 * which each flow words. Every add-on is priced per stay and summed. The results pages, the Extras
 * and Options components, the checkout, reservation validation and manual reservations in the CP
 * all price add-ons here.
 */
class AddonQuote
{
    /** @var list<array{date_start: mixed, date_end: mixed, quantity: mixed, rate_id: mixed}> */
    public readonly array $stays;

    /**
     * @param  iterable<array|\ArrayAccess>  $stays  each with date_start, date_end, quantity and rate_id
     * @param  ?Collection  $customer  the checkout-form data a custom-priced extra multiplies by. Null leaves
     *                                 that to Extra::getCustomPrice(): the search's customer data, else 1.
     */
    public function __construct(
        public readonly string $itemId,
        iterable $stays,
        public readonly ?Collection $customer = null,
    ) {
        $this->stays = collect($stays)->map(fn ($stay) => [
            'date_start' => $stay['date_start'],
            'date_end' => $stay['date_end'],
            'quantity' => $stay['quantity'] ?? 1,
            'rate_id' => $stay['rate_id'] ?? null,
        ])->values()->all();
    }

    /** A reservation's own stay, or each child's of a parent, priced with its customer's data. */
    public static function forReservation(Reservation $reservation): self
    {
        return new self($reservation->item_id, $reservation->isParent() ? $reservation->childs : [$reservation], $reservation->customerData);
    }

    /** The entry's extras a customer can select, each priced for one unit; 'original_price' is its own price. */
    public function extras(): Collection
    {
        return $this->selectableExtras()->with('category')->get()->each(function (Extra $extra) {
            $extra->original_price = $extra->price->format();
            $extra->price = $this->unitPrice($extra)->format();
        });
    }

    /** The entry's options a customer can pick, with their published values priced; 'original_price' is a value's own. */
    public function options(): Collection
    {
        return Option::entry($this->itemId)->selectable()->get()->each(fn (Option $option) => $option->values->each(function (OptionValue $value) {
            $value->original_price = $value->price->format();
            $value->price = $this->valuePrice($value)->format();
        }));
    }

    /**
     * Price a selection. Extra rows carry 'id' and 'quantity', option rows 'id' and 'value'; other keys
     * pass through. A row the entry can book gets its 'price': an extra's for one unit, plus its line
     * as 'total' above one unit (rowTotal()), an option's for its value. A row it
     * can't book keeps what it had, and 'blockers' says why, in row order, with the row and its extra
     * when there is one; each flow words them. The totals add up the rows it can book.
     *
     * @return array{extras: Collection, options: Collection, extras_total: PriceClass, options_total: PriceClass, blockers: list<array{reason: AddonBlocker, row: array, extra: ?Extra}>}
     */
    public function price(iterable $extras = [], iterable $options = []): array
    {
        $blockers = [];
        $extrasTotal = Price::create(0);
        $optionsTotal = Price::create(0);

        $extras = collect($extras);
        $bookable = $this->selectableExtras()->findMany($extras->pluck('id'))->keyBy('id');

        $extras = $extras->map(function (array $row) use ($bookable, &$blockers, $extrasTotal) {
            $extra = $bookable->get((int) ($row['id'] ?? 0));

            if (! $extra) {
                $blockers[] = ['reason' => AddonBlocker::ExtraUnavailable, 'row' => $row, 'extra' => null];

                return $row;
            }

            $quantity = (int) ($row['quantity'] ?? 0);

            if ($reason = $this->extraBlocker($extra, $quantity)) {
                $blockers[] = ['reason' => $reason, 'row' => $row, 'extra' => $extra];
            }

            $row['price'] = $this->unitPrice($extra)->format();

            // A dynamic-pricing minimum or maximum clamps the line, so it is not price × quantity. One
            // unit's line is its price, so it is priced only above one.
            unset($row['total']);
            if ($quantity > 1) {
                $row['total'] = $this->lineTotal($extra, $quantity)->format();
            }

            $extrasTotal->add(self::rowTotal($row));

            return $row;
        });

        $options = collect($options);
        $selectable = Option::entry($this->itemId)->selectable()->findMany($options->pluck('id'))->keyBy('id');

        $options = $options->map(function (array $row) use ($selectable, &$blockers, $optionsTotal) {
            $option = $selectable->get((int) ($row['id'] ?? 0));

            // Matched as the id the pivot books: a JSON true passes the integer rule and loosely equals
            // every id, but the database reads it as 1.
            $value = $option?->values->firstWhere('id', (int) ($row['value'] ?? 0));

            if (! $value) {
                $blockers[] = [
                    'reason' => $option ? AddonBlocker::OptionValueUnavailable : AddonBlocker::OptionUnavailable,
                    'row' => $row,
                    'extra' => null,
                ];

                return $row;
            }

            $row['price'] = $this->valuePrice($value)->format();
            $optionsTotal->add(Price::create($row['price']));

            return $row;
        });

        return [
            'extras' => $extras,
            'options' => $options,
            'extras_total' => $extrasTotal,
            'options_total' => $optionsTotal,
            'blockers' => $blockers,
        ];
    }

    /**
     * The extras the entry's conditions require for any of the stays and the selection lacks, each
     * with the conditions' reasons: [extra id => Collection<string>].
     */
    public function missingRequiredExtras(Collection $extras, Collection $options): Collection
    {
        // One evaluator for every stay, so its category lookups are memoized across them.
        $conditions = new ExtraCondition;

        return collect($this->stays)->reduce(function (Collection $missing, array $stay) use ($conditions, $extras, $options) {
            $required = $conditions->hasRequiredExtrasSelected($this->itemId, $stay + ['extras' => $extras, 'options' => $options]);

            // union() keeps the extra ids as keys; the first stay's reasons win.
            return $required === true ? $missing : $missing->union($required);
        }, collect());
    }

    /** The entry's published required options the selection has no value for. */
    public function missingRequiredOptions(Collection $options): Collection
    {
        $selected = $options->pluck('id')->map(fn ($id) => (int) $id);

        return Option::entry($this->itemId)
            ->where('published', true)
            ->where('required', true)
            ->without('values')
            ->get(['id', 'name'])
            ->reject(fn (Option $option) => $selected->contains((int) $option->id));
    }

    /** One unit of $extra for the stays. */
    public function unitPrice(Extra $extra): PriceClass
    {
        // A fresh clone per stay: priceForDates() writes dynamic pricing back into the price.
        return $this->sum(fn (array $stay) => Price::create((clone $extra)->priceForDates($this->pricingData($stay, $extra))));
    }

    /** The line $quantity units of $extra are charged: Extra::calculatePrice() clamps the line, not the unit. */
    public function lineTotal(Extra $extra, int $quantity): PriceClass
    {
        return $this->sum(fn (array $stay) => (clone $extra)->calculatePrice($this->pricingData($stay, $extra), $quantity));
    }

    public function valuePrice(OptionValue $value): PriceClass
    {
        return $this->sum(fn (array $stay) => $value->calculatePrice($this->pricingData($stay)));
    }

    /** A booked option's value, which Option::calculatePrice() still finds once deleted. */
    public function optionPrice(Option $option, $valueId): PriceClass
    {
        return $this->sum(fn (array $stay) => $option->calculatePrice($this->pricingData($stay), $valueId));
    }

    /**
     * Whether $extra multiplies by a customer field that the customer data lacks or that isn't a number.
     * It is priced ×1 then, and can't be booked. Without customer data it isn't checked.
     */
    public function lacksCustomPriceField(Extra $extra): bool
    {
        return $this->customer !== null
            && $extra->price_type === 'custom'
            && ! is_numeric($this->customer->get($extra->custom));
    }

    /**
     * What a selected extra row adds to the total: its line as 'total' when price() carried one (a
     * dynamic-pricing minimum/maximum clamps the line, so it is not price × quantity), else price ×
     * quantity.
     */
    public static function rowTotal(array $extra): PriceClass
    {
        if (isset($extra['total'])) {
            return Price::create($extra['total']);
        }

        return Price::create($extra['price'])->multiply($extra['quantity']);
    }

    /** Why $quantity units of $extra can't be booked, or null. */
    protected function extraBlocker(Extra $extra, int $quantity): ?AddonBlocker
    {
        if ($quantity < 1) {
            return AddonBlocker::ExtraQuantityBelowOne;
        }

        return $extra->quantityBlocker($quantity)
            ?? ($this->lacksCustomPriceField($extra) ? AddonBlocker::ExtraCustomPriceMissing : null);
    }

    /** The entry's extras a customer can select (Extra::scopeSelectable()), without the conditions pricing doesn't read. */
    protected function selectableExtras(): Builder
    {
        return Extra::whereHas('entries', fn ($query) => $query->where('resrv_entries.item_id', $this->itemId))
            ->selectable()
            ->without('conditions');
    }

    protected function pricingData(array $stay, ?Extra $extra = null): array
    {
        $data = $stay + ['item_id' => $this->itemId];

        if ($this->customer !== null) {
            $data['customer'] = $extra && $this->lacksCustomPriceField($extra)
                ? $this->customer->merge([$extra->custom => 1])
                : $this->customer;
        }

        return $data;
    }

    protected function sum(Closure $price): PriceClass
    {
        return Price::create(0)->add(...array_map($price, $this->stays));
    }
}

<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Enums\AvailabilityChangeReason;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Enums\RateSorting;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Repositories\AvailabilityRepository as AvailabilityRepositoryClass;
use Reach\StatamicResrv\Resources\AvailabilityItemResource;
use Reach\StatamicResrv\Resources\AvailabilityResource;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Traits\HandlesPricing;

class Availability extends Model implements AvailabilityContract
{
    use HandlesAvailabilityDates, HandlesMultisiteIds, HandlesPricing, HasFactory;

    protected $table = 'resrv_availabilities';

    protected $fillable = [
        'statamic_id',
        'date',
        'price',
        'available',
        'available_blocked',
        'rate_id',
        'pending',
    ];

    protected $casts = [
        'price' => PriceClass::class,
        'pending' => 'array',
    ];

    protected RateSorting $rateSorting = RateSorting::Order;

    /**
     * Per-instance caches for entry/rate lookups. The extra-days search reuses one Availability
     * instance across every date period, so these avoid repeated queries for the same static data.
     *
     * @var array<string, Entry>
     */
    private array $resolvedResrvEntries = [];

    /** @var array<string, Collection> */
    private array $publishedRatesCache = [];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'statamic_id', 'item_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    /**
     * Defaults to RateSorting::Price to preserve the historical batched/browse behaviour
     * (and direct callers that depend on the cheapest rate surfacing first). The Livewire
     * AvailabilityCollection component opts into RateSorting::Order via resolveRateSorting()
     * to match the single-entry getAvailabilityForEntry() default.
     */
    public function getAvailable($data, $entries = null, RateSorting $rateSorting = RateSorting::Price)
    {
        ExpireReservations::dispatchSync();

        $this->rateSorting = $rateSorting;
        $this->initiateAvailability($data);

        return $this->getAvailabilityCollection($entries)->resolve();
    }

    public function getAvailabilityForEntry($data, $statamic_id, bool $expireReservations = true, RateSorting $rateSorting = RateSorting::Order)
    {
        if ($expireReservations) {
            ExpireReservations::dispatchSync();
        }

        $this->rateSorting = $rateSorting;
        $this->initiateAvailability($data);

        return $this->getSpecificItemCollection($statamic_id)->resolve();
    }

    public function confirmAvailability($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItemCollection($statamic_id)->resolve();

        return $availability['message']['status'] == 1;
    }

    public function confirmAvailabilityAndPrice($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItemCollection($statamic_id)->resolve();

        if ($availability['message']['status'] != 1 || $availability['data']['price'] != $data['price']) {
            return false;
        }

        if ($this->rateId) {
            try {
                app(AvailabilityRepositoryClass::class)->validateMaxAvailable(
                    rateId: $this->rateId,
                    dateStart: $this->date_start,
                    dateEnd: $this->date_end,
                    quantity: $this->quantity,
                );
            } catch (AvailabilityException) {
                return false;
            }
        }

        return true;
    }

    public function getPricing($data, $statamic_id, $onlyPrice = false)
    {
        $this->initiateAvailabilityUnsafe($data);
        $entry = $this->getDefaultSiteEntry($statamic_id);

        $results = AvailabilityRepository::itemPricesBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            statamic_id: $entry->id(),
            rateId: $this->rateId,
        )
            ->first();

        if (! $results) {
            return false;
        }

        $prices = $this->getPrices($results->prices, $entry->id());

        if ($prices['reservationPrice'] === null) {
            return false;
        }

        if ($onlyPrice) {
            return $prices['reservationPrice']->format();
        }

        return [
            'price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice']?->format(),
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
        ];
    }

    protected function getResultsForItem(Entry $entry, ?int $rateId = null)
    {
        return AvailabilityRepository::itemAvailableBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id(),
            rateId: $rateId ?? $this->rateId,
        )
            ->get();
    }

    public function decrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?int $rateId = null, bool $isChildReservation = false, ?AvailabilityChangeReason $reason = null, ?int $parentReservationId = null): void
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'rate_id' => $rateId,
        ]);

        AvailabilityRepository::decrement(
            date_start: $this->date_start,
            date_end: $this->date_end,
            quantity: $this->quantity,
            statamic_id: $statamic_id,
            rateId: $rateId,
            reservationId: $reservationId,
            isChildReservation: $isChildReservation,
            reason: $reason,
            parentReservationId: $parentReservationId,
        );
    }

    public function incrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?int $rateId = null, bool $isChildReservation = false, ?AvailabilityChangeReason $reason = null, ?int $parentReservationId = null): void
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'rate_id' => $rateId,
        ]);

        AvailabilityRepository::increment(
            date_start: $this->date_start,
            date_end: $this->date_end,
            quantity: $this->quantity,
            statamic_id: $statamic_id,
            rateId: $rateId,
            reservationId: $reservationId,
            isChildReservation: $isChildReservation,
            reason: $reason,
            parentReservationId: $parentReservationId,
        );
    }

    public function deleteForDates(string $date_start, string $date_end, string $statamic_id, ?int $rateId = null): int
    {
        return AvailabilityRepository::delete(
            date_start: $date_start,
            date_end: $date_end,
            statamic_id: $statamic_id,
            rateId: $rateId,
        );
    }

    public function block(): bool
    {
        // Only block if it's not already zero
        if ($this->available > 0) {
            $this->available_blocked = $this->available;
            $this->available = 0;
            $this->saveQuietly();

            return true;
        }

        return false;
    }

    public function unblock(): bool
    {
        // Only unblock if we have an original value stored and if it's blocked
        if ($this->available_blocked !== null && $this->available == 0) {
            $this->available = $this->available_blocked;
            $this->available_blocked = null;
            $this->saveQuietly();

            return true;
        }

        return false;
    }

    protected function getAvailabilityCollection(?array $entries = null)
    {
        $request = $this->requestCollection();
        $available = $this->availableForDates();

        $filteredAvailable = $available
            ->when($entries, fn ($query) => $query->whereIn('statamic_id', $entries));

        $baseRateIds = $filteredAvailable->pluck('rate_id')->unique()->filter()->values();
        $ratesMap = Rate::withoutGlobalScope(OrderScope::class)
            ->whereIn('id', $baseRateIds)
            ->with('entries')
            ->get()
            ->keyBy('id');

        // When browsing without a rate_id, find published shared rates that reference the base rates
        $sharedByBase = collect();
        if (! $this->rateId) {
            $sharedRates = Rate::withoutGlobalScope(OrderScope::class)
                ->whereIn('base_rate_id', $baseRateIds)
                ->where('availability_type', 'shared')
                ->where('published', true)
                ->with('entries')
                ->get();

            $sharedRates->each(fn ($rate) => $ratesMap->put($rate->id, $rate));
            $sharedByBase = $sharedRates->groupBy('base_rate_id');
        }

        // When filtering by a specific shared rate, validate against its restrictions
        $selectedSharedRate = null;
        if ($this->rateId) {
            $rate = $this->getRate();
            if ($rate?->isShared()) {
                $rate->loadMissing('entries');
                $selectedSharedRate = $rate;
                $ratesMap->put($rate->id, $rate);
            }
        }

        // Pre-compute exhausted dates for all capped shared rates in one query; capacity checks
        // below become in-memory lookups. getExhaustedDatesForRates ignores uncapped rates.
        $exhaustedByRate = app(AvailabilityRepositoryClass::class)
            ->getExhaustedDatesForRates($ratesMap->values(), $this->quantity, $this->date_start, $this->date_end);

        // Pre-load shared-independent pricing (rate overrides + base-rate prices) for every grouped
        // entry in one query each, so resolveSharedIndependentPrices() does no per-item queries below.
        $statamicIds = $filteredAvailable->pluck('statamic_id')->unique()->values()->all();
        $prefetchedPricing = [
            'overrides' => $this->loadRateOverridesForRates($ratesMap->values(), $statamicIds, $this->date_start, $this->date_end),
            'basePrices' => $this->loadBasePricesForRates($ratesMap->values(), $statamicIds, $this->date_start, $this->date_end),
        ];

        $availableWithPricing = $filteredAvailable
            ->groupBy('statamic_id')
            ->map(function ($items) use ($ratesMap, $sharedByBase, $selectedSharedRate, $exhaustedByRate, $prefetchedPricing) {
                $processed = collect();

                foreach ($items as $item) {
                    if ($selectedSharedRate) {
                        if ($this->ratePassesRestrictions($selectedSharedRate, $exhaustedByRate->get($selectedSharedRate->id, collect()))
                            && $selectedSharedRate->appliesToEntry($item->statamic_id)
                        ) {
                            if ($populated = $this->populateAvailability($item, rateId: $selectedSharedRate->id, rate: $selectedSharedRate, prefetchedPricing: $prefetchedPricing)) {
                                $processed->push($populated);
                            }
                        }

                        continue;
                    }

                    $baseRate = $ratesMap->get($item->rate_id);

                    if ($baseRate && $this->ratePassesRestrictions($baseRate, $exhaustedByRate->get($baseRate->id, collect())) && $baseRate->appliesToEntry($item->statamic_id)) {
                        if ($populated = $this->populateAvailability($item, rate: $baseRate)) {
                            $processed->push($populated);
                        }
                    }

                    foreach ($sharedByBase->get($item->rate_id, collect()) as $sharedRate) {
                        if (! $this->ratePassesRestrictions($sharedRate, $exhaustedByRate->get($sharedRate->id, collect()))) {
                            continue;
                        }
                        if (! $sharedRate->appliesToEntry($item->statamic_id)) {
                            continue;
                        }
                        if ($populated = $this->populateAvailability($item, rateId: $sharedRate->id, rate: $sharedRate, prefetchedPricing: $prefetchedPricing)) {
                            $processed->push($populated);
                        }
                    }
                }

                $sorted = match ($this->rateSorting) {
                    RateSorting::Price => $processed->sortBy(
                        fn ($row) => (int) Price::create($row->get('price'))->raw()
                    ),
                    // Mirror OrderScope (orderBy('order')->orderBy(id)). $ratesMap holds both
                    // base and shared rates keyed by id; a row whose rate is somehow absent
                    // sinks to the end.
                    RateSorting::Order => $processed->sortBy(fn ($row) => [
                        (int) ($ratesMap->get($row->get('rate_id'))?->order ?? PHP_INT_MAX),
                        (int) $row->get('rate_id'),
                    ]),
                };

                return $sorted->values();
            })
            ->filter(fn ($items) => $items->isNotEmpty());

        return new AvailabilityResource($availableWithPricing, $request);
    }

    /**
     * Returns (and memoizes) the resrv entry mirror row for a Statamic id.
     */
    protected function resolveResrvEntry($statamic_id): Entry
    {
        return $this->resolvedResrvEntries[$statamic_id] ??= Entry::whereItemId($statamic_id);
    }

    /**
     * Returns (and memoizes) an entry's published rate set. Date-restriction checks still run
     * per call; only the DB query is cached.
     */
    protected function publishedRatesForEntry($itemId, bool $withEntries = false): Collection
    {
        $key = $itemId.'|'.($withEntries ? 'with-entries' : 'bare');

        return $this->publishedRatesCache[$key] ??= ($withEntries
            ? Rate::forEntry($itemId)->published()->with('entries')->get()
            : Rate::forEntry($itemId)->published()->get());
    }

    protected function getSpecificItemCollection($statamic_id)
    {
        $resrvEntry = $this->resolveResrvEntry($statamic_id);

        $request = $this->requestCollection();

        $availability = collect();

        if ($resrvEntry->isDisabled()) {
            return new AvailabilityItemResource($availability, $request);
        }

        if ($this->showAllRates) {
            return $this->getMultipleRatesAvailability($resrvEntry, $request);
        }

        $rate = null;

        if ($this->rateId) {
            $rate = $this->getRate();
            if (! $rate) {
                return new AvailabilityItemResource($availability, $request);
            }
            $rate->loadMissing('entries');
            if (! $rate->appliesToEntry($statamic_id) || ! $this->ratePassesRestrictions($rate)) {
                return new AvailabilityItemResource($availability, $request);
            }
        }

        if (! $rate && $this->rateSorting === RateSorting::Price) {
            return $this->getCheapestRateAvailability($resrvEntry, $request);
        }

        $results = $this->getResultsForItem($resrvEntry)->first();

        if (! $results) {
            return new AvailabilityItemResource($availability, $request);
        }

        if (! $rate) {
            $rate = $this->resolveRateForResult($results, $resrvEntry);
            if (! $rate) {
                return new AvailabilityItemResource($availability, $request);
            }
        }

        if ($populated = $this->populateAvailability($results, rateId: $rate->id, rate: $rate)) {
            $availability->push($populated);
        }

        return new AvailabilityItemResource($availability, $request);
    }

    protected function resolveRateForResult($result, Entry $entry): ?Rate
    {
        $rates = $this->publishedRatesForEntry($entry->item_id, withEntries: true);

        foreach ($rates as $rate) {
            $matchesResult = ($rate->isShared() && $rate->base_rate_id)
                ? $rate->base_rate_id == $result->rate_id
                : $rate->id == $result->rate_id;

            if ($matchesResult && $this->ratePassesRestrictions($rate)) {
                return $rate;
            }
        }

        return null;
    }

    protected function buildRatesAvailabilityCollection(Entry $entry): Collection
    {
        $availability = collect();

        $directResults = AvailabilityRepository::itemAvailableBetweenForAllRates(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id()
        )->get()->keyBy('rate_id');

        $rates = $this->publishedRatesForEntry($entry->item_id);

        foreach ($rates as $rate) {
            $result = ($rate->isShared() && $rate->base_rate_id)
                ? $directResults->get($rate->base_rate_id)
                : $directResults->get($rate->id);

            if (! $result) {
                continue;
            }

            if (! $this->ratePassesRestrictions($rate)) {
                continue;
            }

            if ($populated = $this->populateAvailability($result, $rate->title, rateId: $rate->id, rate: $rate)) {
                $availability->put($rate->id, $populated);
            }
        }

        return $availability;
    }

    protected function getMultipleRatesAvailability(Entry $entry, $request)
    {
        return new AvailabilityItemResource(
            $this->buildRatesAvailabilityCollection($entry)->sortBy(fn ($row) => (int) Price::create($row->get('price'))->raw()),
            $request
        );
    }

    protected function getCheapestRateAvailability(Entry $entry, $request)
    {
        // Scan for the lowest price; keep the FIRST rate that reaches it (strict lessThan) so
        // ties resolve to lowest-order rate. Comparison goes through Price (integer cents).
        $cheapest = $this->buildRatesAvailabilityCollection($entry)
            ->reduce(function ($cheapest, $rate) {
                if ($cheapest === null) {
                    return $rate;
                }

                return Price::create($rate->get('price'))->lessThan(Price::create($cheapest->get('price'))) ? $rate : $cheapest;
            });

        return new AvailabilityItemResource(
            $cheapest === null ? collect() : collect([$cheapest]),
            $request
        );
    }

    protected function ratePassesRestrictions(Rate $rate, ?Collection $exhaustedDates = null): bool
    {
        return $rate->published
            && $rate->isAvailableForDates($this->date_start, $this->date_end)
            && $rate->meetsStayRestrictions($this->duration)
            && $rate->meetsBookingLeadTime($this->date_start)
            && $this->rateHasCapacity($rate, $exhaustedDates);
    }

    protected function rateHasCapacity(Rate $rate, ?Collection $exhaustedDates = null): bool
    {
        if (! $rate->isShared() || ! $rate->max_available) {
            return true;
        }

        // A quantity above the cap can never be satisfied; the exhausted-date set only flags
        // already-booked dates, so reject it up front.
        if ($this->quantity > $rate->max_available) {
            return false;
        }

        // Use the pre-computed exhausted-date set when available (browse path).
        if ($exhaustedDates !== null) {
            return ! $this->periodIntersectsExhaustedDates($exhaustedDates);
        }

        return app(AvailabilityRepositoryClass::class)->checkMaxAvailable(
            rateId: $rate->id,
            dateStart: $this->date_start,
            dateEnd: $this->date_end,
            quantity: $this->quantity,
        );
    }

    /**
     * Returns true when any night in [date_start, date_end) is in the exhausted-date set.
     * date_end is exclusive — the checkout day is free.
     */
    private function periodIntersectsExhaustedDates(Collection $exhaustedDates): bool
    {
        if ($exhaustedDates->isEmpty()) {
            return false;
        }

        $lookup = $exhaustedDates->flip();

        foreach (CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE) as $date) {
            if ($lookup->has($date->toDateString())) {
                return true;
            }
        }

        return false;
    }

    protected function populateAvailability($results, $label = null, ?int $rateId = null, ?Rate $rate = null, ?array $prefetchedPricing = null): ?Collection
    {
        $effectiveRateId = $rateId ?? $this->rateId ?? $results->rate_id;

        $prices = $this->getPrices($results->prices, $results->statamic_id, $effectiveRateId, $rate, $prefetchedPricing);

        if ($prices['reservationPrice'] === null) {
            return null;
        }

        return collect([
            'price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice']?->format(),
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
            'rate_id' => $effectiveRateId,
            'rateLabel' => $label,
            'cancellation_policy' => $this->resolveCancellationPolicyData($rate, $effectiveRateId),
        ]);
    }

    /**
     * The per-rate cancellation terms exposed in the availability payload so every surface
     * (Livewire results, resrv_search scope, live_availability hook) can render policy labels.
     * Reuses the getRate() memo that getPrices() already warmed for the same row.
     *
     * @return array{policy: string, period: ?int}
     */
    protected function resolveCancellationPolicyData(?Rate $rate, $effectiveRateId): array
    {
        $rate ??= $effectiveRateId ? $this->getRate((int) $effectiveRateId) : null;

        $cancellation = $rate?->effectiveCancellationPolicy() ?? CancellationPolicy::globalDefault();

        return [
            'policy' => $cancellation['policy']->value,
            'period' => $cancellation['period'],
        ];
    }

    protected function availableForDates()
    {
        $results = AvailabilityRepository::availableBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            rateId: $this->rateId,
        )->get();

        $disabled = array_flip($this->getDisabledIds());

        return $results->reject(fn ($item) => isset($disabled[$item->statamic_id]));
    }

    public function getPriceForDates($item, $id)
    {
        $prices = $this->getPrices($item->prices, $id);

        if ($prices['reservationPrice'] === null) {
            return false;
        }

        return [
            'reservation_price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice']?->format(),
        ];
    }

    protected function getRateIdFromResults($results, $statamic_id, $singleItem = false)
    {
        if (! $singleItem) {
            $results = $results->where('statamic_id', $statamic_id);
        }

        if ($results->isEmpty()) {
            return false;
        }

        $rateId = $results->first()->rate_id;
        if (! $results->every(fn ($item) => $item->rate_id == $rateId)) {
            return false;
        }

        return $rateId;
    }

    protected function getDisabledIds(): array
    {
        return Entry::getDisabledIds();
    }

    protected function getPeriod()
    {
        return CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE);
    }

    protected function createPricesCollection($prices)
    {
        return collect(explode(',', $prices))->transform(fn ($price) => Price::create($price));
    }

    protected function requestCollection(): Collection
    {
        return collect([
            'duration' => $this->duration,
            'date_start' => $this->date_start,
            'date_end' => $this->date_end,
            'quantity' => $this->quantity,
            'rate_id' => $this->rateId,
        ]);
    }

    public function getDynamicPricingsForReservation(Reservation $reservation)
    {
        if ($reservation->isParent()) {
            return $this->getDynamicPricingsForParentReservation($reservation);
        }

        return $this->getDynamicPricingsForDates($reservation->item_id, [
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
        ]);
    }

    protected function getDynamicPricingsForParentReservation(Reservation $reservation): DynamicPricing|false
    {
        $allPricings = collect();

        foreach ($reservation->childs as $child) {
            $result = $this->getDynamicPricingsForDates($reservation->item_id, [
                'date_start' => $child->date_start,
                'date_end' => $child->date_end,
                'quantity' => $child->quantity,
                'rate_id' => $child->rate_id,
            ]);

            if ($result && $result->getToApply()) {
                $allPricings = $allPricings->merge($result->getToApply());
            }
        }

        if ($allPricings->isEmpty()) {
            return false;
        }

        $dynamicPricing = new DynamicPricing;
        $dynamicPricing->toApply = $allPricings->unique('id')->values();

        return $dynamicPricing;
    }

    protected function getDynamicPricingsForDates(string $itemId, array $data): DynamicPricing|false
    {
        $entry = $this->getDefaultSiteEntry($itemId);

        $availability = new self;
        $availability->initiateAvailabilityUnsafe($data);

        $dbPrices = AvailabilityRepository::itemPricesBetween(
            date_start: $availability->date_start,
            date_end: $availability->date_end,
            statamic_id: $entry->id(),
            rateId: $availability->rateId,
        )
            ->first();

        if (! $dbPrices) {
            return false;
        }

        $prices = $availability->getPrices($dbPrices['prices'], $entry->id());

        if ($prices['reservationPrice'] === null) {
            return false;
        }

        return DynamicPricing::searchForAvailability(
            $entry->id(),
            $prices['originalPrice'] ?? $prices['reservationPrice'],
            $availability->date_start,
            $availability->date_end,
            $availability->duration
        );
    }

    public function getAvailabilityCalendar(string $id, ?string $rateId): array
    {
        $rate = $rateId ? Rate::find((int) $rateId) : null;

        if ($rateId && ! $rate) {
            return [];
        }

        if ($rate) {
            $rate->loadMissing('entries');
            if (! $rate->published || ! $rate->appliesToEntry($id)) {
                return [];
            }
        }

        $resolvedRateId = $rate ? (($rate->base_rate_id && $rate->isShared()) ? (int) $rate->base_rate_id : $rate->id) : null;

        $results = $this->where('statamic_id', $id)
            ->where('date', '>=', now()->startOfDay()->toDateString())
            ->when($resolvedRateId, function ($query) use ($resolvedRateId) {
                return $query->where('rate_id', $resolvedRateId);
            })
            ->orderBy('price')
            ->get(['statamic_id', 'date', 'available', 'price', 'rate_id']);

        if ($rate && $resolvedRateId) {
            $rewriteRateId = $resolvedRateId !== $rate->id;

            if ($rewriteRateId || $rate->isRelative()) {
                $results->transform(function ($item) use ($rate, $rewriteRateId) {
                    if ($rewriteRateId) {
                        $item->rate_id = $rate->id;
                    }
                    if ($rate->isRelative()) {
                        $item->price = $rate->calculatePrice($item->price);
                    }

                    return $item;
                });
            }

            if ($rate->hasIndependentSharedPricing()) {
                $overrides = $this->loadRateOverrides($rate, $id, now()->startOfDay()->toDateString());
                $results = $this->applySharedIndependentOverrides(
                    $results,
                    $rate,
                    $overrides,
                    fn ($item) => Carbon::parse($item->date)->toDateString(),
                );
            }

            $results = $results->filter(
                fn ($item) => $rate->dateIsWithinWindow($item->date) && $rate->meetsBookingLeadTime($item->date)
            );

            // Skip for empty set: no dates to reject, and a null range would cause an unbounded scan.
            if ($results->isNotEmpty() && $rate->isShared() && $rate->max_available) {
                [$rangeStart, $rangeEnd] = $this->exhaustedDateWindow($results);
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate, 1, $rangeStart, $rangeEnd);
                if ($exhaustedDates->isNotEmpty()) {
                    $results = $results->reject(fn ($item) => $exhaustedDates->contains($item->date));
                }
            }
        }

        if (! $rate) {
            $results = $this->expandCalendarWithPublishedRates($results, $id);
        }

        return $results
            ->groupBy(fn ($item) => Carbon::parse($item->date)->format('Y-m-d'))
            ->map(fn ($item) => $item->sortBy(fn ($row) => (int) $row->price->raw())->firstWhere('available', '>', 0))
            // Drop dates whose every row is sold out (firstWhere returns null) so the calendar
            // contract is "date => cheapest available row", never a null mixed in with arrays.
            ->filter()
            ->toArray();
    }

    protected function expandCalendarWithPublishedRates(Collection $baseResults, string $entryId): Collection
    {
        $entry = Entry::whereItemId($entryId);
        $rates = Rate::forEntry($entry->item_id)->published()->get();

        if ($rates->isEmpty()) {
            // Only fall back for legacy entries with no rate association
            return $baseResults->contains(fn ($row) => $row->rate_id !== null)
                ? collect()
                : $baseResults;
        }

        return $this->expandRatesFromBaseResults($baseResults, $rates);
    }

    public function getAvailableDatesFromDate(string $id, string $dateStart, int $quantity = 1, ?int $rateId = null, bool $showAllRates = false, bool $groupByDate = false): array
    {
        if ($rateId && ! $showAllRates) {
            $rateCheck = Rate::find($rateId);
            if (! $rateCheck) {
                return [];
            }
            $rateCheck->loadMissing('entries');
            if (! $rateCheck->published || ! $rateCheck->appliesToEntry($id)) {
                return [];
            }
        }

        $resolvedRateId = ($rateId && ! $showAllRates)
            ? AvailabilityRepository::resolveBaseRateId($rateId)
            : null;

        $results = $this->where('statamic_id', $id)
            ->where('date', '>=', $dateStart)
            ->where('available', '>=', $quantity)
            ->when($resolvedRateId, fn ($query) => $query->where('rate_id', $resolvedRateId))
            ->orderBy('date')
            ->orderBy('price')
            ->get(['statamic_id', 'date', 'available', 'price', 'rate_id']);

        if ($rateId && ! $showAllRates) {
            $rate = $rateCheck;

            if ($rate?->date_end && Carbon::parse($dateStart)->gt($rate->date_end)) {
                return [];
            }

            if ($rate) {
                $results = $results->filter(fn ($item) => $rate->dateIsWithinWindow($item->date) && $rate->meetsBookingLeadTime($item->date));
            }

            // A quantity above the cap can never be satisfied; the exhausted-date set only flags
            // already-booked dates so it would miss this. Drop every date up front.
            if ($rate?->isShared() && $rate->max_available && $quantity > $rate->max_available) {
                return [];
            }

            // Skip for empty set: no dates to reject, and a null range would cause an unbounded scan.
            if ($results->isNotEmpty() && $rate?->isShared() && $rate->max_available) {
                [$rangeStart, $rangeEnd] = $this->exhaustedDateWindow($results);
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate, $quantity, $rangeStart, $rangeEnd);
                if ($exhaustedDates->isNotEmpty()) {
                    $results = $results->reject(fn ($item) => $exhaustedDates->contains($item->date));
                }
            }

            $rewriteRateId = $resolvedRateId && $resolvedRateId !== $rateId;

            if ($rewriteRateId || $rate?->isRelative()) {
                $results->transform(function ($item) use ($rate, $rateId, $rewriteRateId) {
                    if ($rewriteRateId) {
                        $item->rate_id = $rateId;
                    }
                    if ($rate?->isRelative()) {
                        $item->price = $rate->calculatePrice($item->price);
                    }

                    return $item;
                });
            }

            if ($rate?->hasIndependentSharedPricing()) {
                $overrides = $this->loadRateOverrides($rate, $id, $dateStart);
                $results = $this->applySharedIndependentOverrides(
                    $results,
                    $rate,
                    $overrides,
                    fn ($item) => Carbon::parse($item->date)->toDateString(),
                );
            }
        }

        if ($showAllRates) {
            $results = $this->expandSharedRatesForDates($results, $id, $dateStart, $quantity);
        }

        $formatDate = fn ($date) => Carbon::parse($date)->format('Y-m-d');

        if ($groupByDate) {
            return $results
                ->groupBy(fn ($item) => $formatDate($item->date))
                ->map(function ($items) {
                    return $items->mapWithKeys(function ($item) {
                        return [
                            $item->rate_id => [
                                'available' => $item->available,
                                'price' => $item->price->format(),
                            ],
                        ];
                    })->toArray();
                })
                ->toArray();
        }

        return $results
            ->groupBy('rate_id')
            ->sortBy(fn ($items) => (int) $items->first()->price->raw())
            ->map(function ($items) use ($formatDate) {
                return $items->mapWithKeys(function ($item) use ($formatDate) {
                    return [
                        $formatDate($item->date) => [
                            'available' => $item->available,
                            'price' => $item->price->format(),
                        ],
                    ];
                })->toArray();
            })
            ->toArray();
    }

    protected function expandSharedRatesForDates(Collection $baseResults, string $entryId, string $dateStart, int $quantity = 1): Collection
    {
        $entry = Entry::whereItemId($entryId);
        $rates = Rate::forEntry($entry->item_id)->published()->get();

        return $this->expandRatesFromBaseResults($baseResults, $rates, $quantity, $dateStart);
    }

    /**
     * Exclusive [start, end) window covering the given availability rows, so the exhausted-date
     * lookup only loads overlapping reservations. End is pushed one day past the last night
     * (date_end is exclusive). Returns [null, null] for an empty set.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function exhaustedDateWindow(Collection $results): array
    {
        $dates = $results->pluck('date')->filter()->map(fn ($d) => Carbon::parse($d)->toDateString());

        if ($dates->isEmpty()) {
            return [null, null];
        }

        return [$dates->min(), Carbon::parse($dates->max())->addDay()->toDateString()];
    }

    private function expandRatesFromBaseResults(Collection $baseResults, Collection $rates, int $quantity = 1, ?string $dateStart = null): Collection
    {
        // With no base rows there is nothing to expand, and an empty set would produce a null
        // date window causing an unbounded exhausted-date scan.
        if ($baseResults->isEmpty()) {
            return collect();
        }

        $baseGrouped = $baseResults->groupBy('rate_id');
        $expanded = collect();

        $statamicIds = $baseResults->pluck('statamic_id')->unique()->values()->all();

        $dates = $baseResults->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString());
        $rangeStart = $dates->min();
        $rangeEnd = $dates->max();

        // date_end is exclusive, so push the bound one day past the last night.
        $exhaustedRangeEnd = $rangeEnd ? Carbon::parse($rangeEnd)->addDay()->toDateString() : null;

        $exhaustedByRate = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRates($rates, $quantity, $rangeStart, $exhaustedRangeEnd);

        $overridesByRate = $this->loadRateOverridesForRates($rates, $statamicIds, $rangeStart, $rangeEnd);

        foreach ($rates as $rate) {
            if ($dateStart && $rate->date_end && Carbon::parse($dateStart)->gt($rate->date_end)) {
                continue;
            }

            $sourceRateId = ($rate->isShared() && $rate->base_rate_id)
                ? $rate->base_rate_id
                : $rate->id;

            $sourceRows = $baseGrouped->get($sourceRateId);
            if (! $sourceRows) {
                continue;
            }

            $overrides = $overridesByRate->get($rate->id, collect());

            $rateRows = $sourceRows->map(function ($row) use ($rate) {
                $clone = clone $row;
                $clone->rate_id = $rate->id;
                if ($rate->isRelative()) {
                    $clone->price = $rate->calculatePrice($clone->price);
                }

                return $clone;
            });

            $rateRows = $this->applySharedIndependentOverrides(
                $rateRows,
                $rate,
                $overrides,
                fn ($row) => $row->statamic_id.'|'.Carbon::parse($row->date)->toDateString(),
            );

            $rateRows = $rateRows->filter(fn ($row) => $rate->dateIsWithinWindow($row->date) && $rate->meetsBookingLeadTime($row->date));

            // A quantity above the cap can never be satisfied; the exhausted-date set only flags
            // already-booked dates so it would miss this. Drop every row for the rate up front.
            if ($rate->isShared() && $rate->max_available && $quantity > $rate->max_available) {
                continue;
            }

            $exhaustedDates = $exhaustedByRate->get($rate->id, collect());
            if ($exhaustedDates->isNotEmpty()) {
                $rateRows = $rateRows->reject(fn ($row) => $exhaustedDates->contains($row->date));
            }

            $expanded = $expanded->merge($rateRows);
        }

        return $expanded;
    }

    protected function loadRateOverrides(Rate $rate, string $statamicId, ?string $dateStart = null, ?string $dateEnd = null): Collection
    {
        return RatePrice::where('rate_id', $rate->id)
            ->where('statamic_id', $statamicId)
            ->when($dateStart, fn ($q) => $q->where('date', '>=', $dateStart))
            ->when($dateEnd, fn ($q) => $q->where('date', '<=', $dateEnd))
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString());
    }

    protected function loadRateOverridesForRates(Collection $rates, array $statamicIds, ?string $dateStart = null, ?string $dateEnd = null): Collection
    {
        $sharedIndependentIds = $rates
            ->filter(fn ($rate) => $rate->hasIndependentSharedPricing())
            ->pluck('id')
            ->unique()
            ->values();

        if ($sharedIndependentIds->isEmpty() || empty($statamicIds)) {
            return collect();
        }

        return RatePrice::whereIn('rate_id', $sharedIndependentIds->all())
            ->whereIn('statamic_id', $statamicIds)
            ->when($dateStart, fn ($q) => $q->where('date', '>=', $dateStart))
            ->when($dateEnd, fn ($q) => $q->where('date', '<=', $dateEnd))
            ->get()
            ->groupBy('rate_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($row) => $row->statamic_id.'|'.Carbon::parse($row->getRawOriginal('date'))->toDateString());
            });
    }

    /**
     * Batch-loads base-rate availability prices, keyed by base rate id then "statamic_id|date",
     * for every shared-independent rate that falls back to its base rate on un-overridden dates.
     */
    protected function loadBasePricesForRates(Collection $rates, array $statamicIds, ?string $dateStart = null, ?string $dateEnd = null): Collection
    {
        $baseRateIds = $rates
            ->filter(fn ($rate) => $rate->hasIndependentSharedPricing() && $rate->base_rate_id)
            ->pluck('base_rate_id')
            ->unique()
            ->values();

        if ($baseRateIds->isEmpty() || empty($statamicIds)) {
            return collect();
        }

        return Availability::whereIn('rate_id', $baseRateIds->all())
            ->whereIn('statamic_id', $statamicIds)
            ->when($dateStart, fn ($q) => $q->where('date', '>=', $dateStart))
            ->when($dateEnd, fn ($q) => $q->where('date', '<', $dateEnd))
            ->get()
            ->groupBy('rate_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($row) => $row->statamic_id.'|'.Carbon::parse($row->getRawOriginal('date'))->toDateString());
            });
    }

    protected function applySharedIndependentOverrides(Collection $results, Rate $rate, Collection $overrides, callable $keyFn): Collection
    {
        if (! $rate->hasIndependentSharedPricing()) {
            return $results;
        }

        $results = $results->map(function ($item) use ($overrides, $keyFn) {
            $key = $keyFn($item);
            if ($overrides->has($key)) {
                $item->price = Price::create($overrides->get($key)->getRawOriginal('price'));
            }

            return $item;
        });

        if ($rate->require_price_override) {
            $results = $results->filter(fn ($item) => $overrides->has($keyFn($item)));
        }

        return $results;
    }
}

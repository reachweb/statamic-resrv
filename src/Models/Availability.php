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
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Repositories\AvailabilityRepository as AvailabilityRepositoryClass;
use Reach\StatamicResrv\Resources\AvailabilityItemResource;
use Reach\StatamicResrv\Resources\AvailabilityResource;
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

    public function getAvailable($data, $entries = null)
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getAvailabilityCollection($entries)->resolve();
    }

    public function getAvailabilityForEntry($data, $statamic_id, bool $expireReservations = true)
    {
        if ($expireReservations) {
            ExpireReservations::dispatchSync();
        }

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

    public function decrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?int $rateId = null): void
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
        );
    }

    public function incrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?int $rateId = null): void
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
        $ratesMap = Rate::withoutGlobalScopes()->whereIn('id', $baseRateIds)->with('entries')->get()->keyBy('id');

        // When browsing without a rate_id, find published shared rates that reference the base rates
        $sharedByBase = collect();
        if (! $this->rateId) {
            $sharedRates = Rate::withoutGlobalScopes()
                ->whereIn('base_rate_id', $baseRateIds)
                ->where('availability_type', 'shared')
                ->where('published', true)
                ->whereNull('deleted_at')
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

        $originalRateId = $this->rateId;

        try {
            $availableWithPricing = $filteredAvailable
                ->groupBy('statamic_id')
                ->map(function ($items) use ($ratesMap, $sharedByBase, $selectedSharedRate, $originalRateId) {
                    $processed = collect();

                    foreach ($items as $item) {
                        if ($selectedSharedRate) {
                            if ($this->ratePassesRestrictions($selectedSharedRate)
                                && $selectedSharedRate->appliesToEntry($item->statamic_id)
                            ) {
                                $this->rateId = $selectedSharedRate->id;
                                $this->cachedRate = $selectedSharedRate;
                                $this->cachedRateId = $selectedSharedRate->id;
                                $processed->push($this->populateAvailability($item));
                            }

                            continue;
                        }

                        $this->rateId = $originalRateId;
                        $baseRate = $ratesMap->get($item->rate_id);

                        if (! $baseRate || ($this->ratePassesRestrictions($baseRate) && $baseRate->appliesToEntry($item->statamic_id))) {
                            $processed->push($this->populateAvailability($item));
                        }

                        foreach ($sharedByBase->get($item->rate_id, collect()) as $sharedRate) {
                            if (! $this->ratePassesRestrictions($sharedRate)) {
                                continue;
                            }
                            if (! $sharedRate->appliesToEntry($item->statamic_id)) {
                                continue;
                            }
                            $this->rateId = $sharedRate->id;
                            $this->cachedRate = $sharedRate;
                            $this->cachedRateId = $sharedRate->id;
                            $processed->push($this->populateAvailability($item));
                        }
                    }

                    return $processed->sortBy('price', SORT_NUMERIC);
                })
                ->filter(fn ($items) => $items->isNotEmpty());
        } finally {
            $this->rateId = $originalRateId;
            $this->cachedRate = null;
            $this->cachedRateId = null;
        }

        return new AvailabilityResource($availableWithPricing, $request);
    }

    protected function getSpecificItemCollection($statamic_id)
    {
        $resrvEntry = Entry::whereItemId($statamic_id);

        $request = $this->requestCollection();

        $availability = collect();

        if ($resrvEntry->isDisabled()) {
            return new AvailabilityItemResource($availability, $request);
        }

        if ($this->showAllRates) {
            return $this->getMultipleRatesAvailability($resrvEntry, $request);
        }

        if ($this->rateId) {
            $rate = $this->getRate();
            if ($rate && ! $this->ratePassesRestrictions($rate)) {
                return new AvailabilityItemResource($availability, $request);
            }
        }

        $results = $this->getResultsForItem($resrvEntry)->first();

        if (! $results) {
            return new AvailabilityItemResource($availability, $request);
        }

        if (! $this->rateId) {
            $rate = $this->resolveRateForResult($results, $resrvEntry);
            if (! $rate) {
                return new AvailabilityItemResource($availability, $request);
            }
            $this->rateId = $rate->id;
            $this->cachedRate = $rate;
            $this->cachedRateId = $rate->id;
        }

        $availability->push($this->populateAvailability($results));

        return new AvailabilityItemResource($availability, $request);
    }

    protected function resolveRateForResult($result, Entry $entry): ?Rate
    {
        $rates = Rate::forEntry($entry->item_id)->published()->get();

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

    protected function getMultipleRatesAvailability(Entry $entry, $request)
    {
        $availability = collect();

        $directResults = AvailabilityRepository::itemAvailableBetweenForAllRates(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id()
        )->get()->keyBy('rate_id');

        $rates = Rate::forEntry($entry->item_id)->published()->get();
        $originalRateId = $this->rateId;

        try {
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

                $this->rateId = $rate->id;
                $this->cachedRate = $rate;
                $this->cachedRateId = $rate->id;
                $availability->put($rate->id, $this->populateAvailability($result, $rate->title));
            }
        } finally {
            $this->rateId = $originalRateId;
        }

        return new AvailabilityItemResource($availability->sortBy('price'), $request);
    }

    protected function ratePassesRestrictions(Rate $rate): bool
    {
        return $rate->published
            && $rate->isAvailableForDates($this->date_start, $this->date_end)
            && $rate->meetsStayRestrictions($this->duration)
            && $rate->meetsBookingLeadTime($this->date_start)
            && $this->rateHasCapacity($rate);
    }

    protected function rateHasCapacity(Rate $rate): bool
    {
        if (! $rate->isShared() || ! $rate->max_available) {
            return true;
        }

        return app(AvailabilityRepositoryClass::class)->checkMaxAvailable(
            rateId: $rate->id,
            dateStart: $this->date_start,
            dateEnd: $this->date_end,
            quantity: $this->quantity,
        );
    }

    protected function populateAvailability($results, $label = null)
    {
        $rateId = $this->rateId ?? $results->rate_id;

        $savedRateId = $this->rateId;
        $this->rateId = $rateId;

        $prices = $this->getPrices($results->prices, $results->statamic_id);

        $this->rateId = $savedRateId;

        return collect([
            'price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice']?->format(),
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
            'rate_id' => $rateId,
            'rateLabel' => $label,
        ]);
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
        $entry = $this->getDefaultSiteEntry($reservation->item_id);

        $this->initiateAvailabilityUnsafe([
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
        ]);

        $dbPrices = AvailabilityRepository::itemPricesBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            statamic_id: $entry->id(),
            rateId: $this->rateId,
        )
            ->first();

        if (! $dbPrices) {
            return false;
        }

        $prices = $this->getPrices($dbPrices['prices'], $entry->id());

        return DynamicPricing::searchForAvailability(
            $entry->id(),
            $prices['originalPrice'] ?? $prices['reservationPrice'],
            $this->date_start,
            $this->date_end,
            $this->duration
        );
    }

    public function getAvailabilityCalendar(string $id, ?string $rateId): array
    {
        $rate = $rateId ? Rate::withoutGlobalScopes()->find((int) $rateId) : null;
        $resolvedRateId = $rate ? (($rate->base_rate_id && $rate->isShared()) ? (int) $rate->base_rate_id : $rate->id) : null;

        $results = $this->where('statamic_id', $id)
            ->where('date', '>=', now()->startOfDay()->toDateString())
            ->when($resolvedRateId, function ($query) use ($resolvedRateId) {
                return $query->where('rate_id', $resolvedRateId);
            })
            ->orderBy('price')
            ->get(['date', 'available', 'price', 'rate_id']);

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

            $results = $results->filter(
                fn ($item) => $rate->dateIsWithinWindow($item->date) && $rate->meetsBookingLeadTime($item->date)
            );

            if ($rate->isShared() && $rate->max_available) {
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate);
                if ($exhaustedDates->isNotEmpty()) {
                    $results = $results->reject(fn ($item) => $exhaustedDates->contains($item->date));
                }
            }
        }

        if (! $rate) {
            $results = $this->expandCalendarWithPublishedRates($results, $id);
        }

        return $results
            ->groupBy(fn ($item) => Carbon::parse($item->date)->format('Y-m-d H:i:s'))
            ->map(fn ($item) => $item->sortBy(fn ($row) => (int) $row->price->raw())->firstWhere('available', '>', 0))
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

        $baseGrouped = $baseResults->groupBy('rate_id');
        $expanded = collect();

        foreach ($rates as $rate) {
            $sourceRateId = ($rate->isShared() && $rate->base_rate_id)
                ? $rate->base_rate_id
                : $rate->id;

            $sourceRows = $baseGrouped->get($sourceRateId);
            if (! $sourceRows) {
                continue;
            }

            $rateRows = $sourceRows->map(function ($row) use ($rate) {
                $clone = clone $row;
                $clone->rate_id = $rate->id;
                if ($rate->isRelative()) {
                    $clone->price = $rate->calculatePrice($clone->price);
                }

                return $clone;
            });

            $rateRows = $rateRows->filter(fn ($row) => $rate->dateIsWithinWindow($row->date) && $rate->meetsBookingLeadTime($row->date));

            if ($rate->isShared() && $rate->max_available) {
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate);
                if ($exhaustedDates->isNotEmpty()) {
                    $rateRows = $rateRows->reject(fn ($row) => $exhaustedDates->contains($row->date));
                }
            }

            $expanded = $expanded->merge($rateRows);
        }

        return $expanded;
    }

    public function getAvailableDatesFromDate(string $id, string $dateStart, int $quantity = 1, ?int $rateId = null, bool $showAllRates = false, bool $groupByDate = false): array
    {
        $resolvedRateId = ($rateId && ! $showAllRates)
            ? AvailabilityRepository::resolveBaseRateId($rateId)
            : null;

        $results = $this->where('statamic_id', $id)
            ->where('date', '>=', $dateStart)
            ->where('available', '>=', $quantity)
            ->when($resolvedRateId, fn ($query) => $query->where('rate_id', $resolvedRateId))
            ->orderBy('date')
            ->orderBy('price')
            ->get(['date', 'available', 'price', 'rate_id']);

        if ($rateId && ! $showAllRates) {
            $rate = Rate::find($rateId);

            if ($rate?->date_end && Carbon::parse($dateStart)->gt($rate->date_end)) {
                return [];
            }

            if ($rate) {
                $results = $results->filter(fn ($item) => $rate->dateIsWithinWindow($item->date) && $rate->meetsBookingLeadTime($item->date));
            }

            if ($rate?->isShared() && $rate->max_available) {
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate, $quantity);
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
        $baseGrouped = $baseResults->groupBy('rate_id');
        $expanded = collect();

        foreach ($rates as $rate) {
            if ($rate->date_end && Carbon::parse($dateStart)->gt($rate->date_end)) {
                continue;
            }

            $sourceRateId = ($rate->isShared() && $rate->base_rate_id)
                ? $rate->base_rate_id
                : $rate->id;

            $sourceRows = $baseGrouped->get($sourceRateId);
            if (! $sourceRows) {
                continue;
            }

            $rateRows = $sourceRows->map(function ($row) use ($rate) {
                $clone = clone $row;
                $clone->rate_id = $rate->id;
                if ($rate->isRelative()) {
                    $clone->price = $rate->calculatePrice($clone->price);
                }

                return $clone;
            });

            $rateRows = $rateRows->filter(fn ($row) => $rate->dateIsWithinWindow($row->date) && $rate->meetsBookingLeadTime($row->date));

            if ($rate->isShared() && $rate->max_available) {
                $exhaustedDates = app(AvailabilityRepositoryClass::class)->getExhaustedDatesForRate($rate, $quantity);
                if ($exhaustedDates->isNotEmpty()) {
                    $rateRows = $rateRows->reject(fn ($row) => $exhaustedDates->contains($row->date));
                }
            }

            $expanded = $expanded->merge($rateRows);
        }

        return $expanded;
    }
}

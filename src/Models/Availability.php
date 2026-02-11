<?php

namespace Reach\StatamicResrv\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Money\Price as PriceClass;
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

    public function getAvailabilityForEntry($data, $statamic_id)
    {
        ExpireReservations::dispatchSync();

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

        return $availability['message']['status'] == 1
            && $availability['data']['price'] == $data['price'];
    }

    public function getPricing($data, $statamic_id, $onlyPrice = false)
    {
        $this->initiateAvailabilityUnsafe($data);
        $entry = $this->getDefaultSiteEntry($statamic_id);

        $results = AvailabilityRepository::itemPricesBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            statamic_id: $entry->id(),
            advanced: $this->advanced,
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

    protected function getResultsForItem(Entry $entry, $advanced = null)
    {
        return AvailabilityRepository::itemAvailableBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id(),
            advanced: $advanced ?? $this->advanced
        )
            ->get();
    }

    public function decrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced, ?int $rateId = null): void
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
            'rate_id' => $rateId,
        ]);

        if ($rateId) {
            AvailabilityRepository::decrementForRate(
                date_start: $this->date_start,
                date_end: $this->date_end,
                quantity: $this->quantity,
                statamic_id: $statamic_id,
                rateId: $rateId,
                reservationId: $reservationId,
            );

            return;
        }

        AvailabilityRepository::decrement(
            date_start: $this->date_start,
            date_end: $this->date_end,
            quantity: $this->quantity,
            statamic_id: $statamic_id,
            advanced: $this->advanced,
            reservationId: $reservationId
        );
    }

    public function incrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced, ?int $rateId = null): void
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
            'rate_id' => $rateId,
        ]);

        if ($rateId) {
            AvailabilityRepository::incrementForRate(
                date_start: $this->date_start,
                date_end: $this->date_end,
                quantity: $this->quantity,
                statamic_id: $statamic_id,
                rateId: $rateId,
                reservationId: $reservationId,
            );

            return;
        }

        AvailabilityRepository::increment(
            date_start: $this->date_start,
            date_end: $this->date_end,
            quantity: $this->quantity,
            statamic_id: $statamic_id,
            advanced: $this->advanced,
            reservationId: $reservationId
        );
    }

    public function deleteForDates(string $date_start, string $date_end, string $statamic_id, ?array $advanced)
    {
        AvailabilityRepository::delete(
            date_start: $date_start,
            date_end: $date_end,
            statamic_id: $statamic_id,
            advanced: $advanced
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

        $availableWithPricing = $available
            ->when($entries, fn ($query) => $query->whereIn('statamic_id', $entries))
            ->groupBy('statamic_id')
            ->map(function ($items) {
                return $items->map(fn ($item) => $this->populateAvailability($item))
                    ->sortBy('price', SORT_NUMERIC);
            });

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

        if ($this->advanced && in_array('any', $this->advanced)) {
            return $this->getMultipleRatesAvailability($resrvEntry, $request);
        }

        $results = $this->getResultsForItem($resrvEntry)->first();

        if (! $results) {
            return new AvailabilityItemResource($availability, $request);
        }

        $availability->push($this->populateAvailability($results));

        return new AvailabilityItemResource($availability, $request);
    }

    protected function getMultipleRatesAvailability(Entry $entry, $request)
    {
        $availability = collect();

        $results = AvailabilityRepository::itemAvailableBetweenForAllRates(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id()
        )->get();

        if ($results->isEmpty()) {
            return new AvailabilityItemResource($availability, $request);
        }

        $rateLabels = Rate::where('statamic_id', $entry->item_id)
            ->pluck('title', 'id')
            ->toArray();

        foreach ($results as $result) {
            $rateId = $result->rate_id;
            $rateLabel = $rateLabels[$rateId] ?? $rateId;
            $availability->put($rateId, $this->populateAvailability($result, $rateLabel));
        }

        return new AvailabilityItemResource($availability->sortBy('price'), $request);
    }

    protected function populateAvailability($results, $label = null)
    {
        $prices = $this->getPrices($results->prices, $results->statamic_id);

        return collect([
            'price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice']?->format(),
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
            'rate_id' => $results->rate_id,
            'property' => $results->rate_id,
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
            advanced: $this->advanced
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
            'property' => $this->advanced,
        ]);
    }

    public function getDynamicPricingsForReservation(Reservation $reservation)
    {
        $entry = $this->getDefaultSiteEntry($reservation->item_id);

        $data = [
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'advanced' => $reservation->rate_id ? (string) $reservation->rate_id : '',
        ];

        $this->initiateAvailabilityUnsafe($data);

        $dbPrices = AvailabilityRepository::itemPricesBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            statamic_id: $entry->id(),
            advanced: $this->advanced,
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
        return $this->where('statamic_id', $id)
            ->where('date', '>=', now()->startOfDay()->toDateString())
            ->when($rateId, function ($query) use ($rateId) {
                return $query->where('rate_id', $rateId);
            })
            ->orderBy('price')
            ->get(['date', 'available', 'price', 'rate_id'])
            ->groupBy(fn ($item) => \Carbon\Carbon::parse($item->date)->format('Y-m-d H:i:s'))
            ->map(fn ($item) => $item->firstWhere('available', '>', 0))
            ->toArray();
    }

    public function getAvailableDatesFromDate(string $id, string $dateStart, int $quantity = 1, ?array $advanced = null, bool $groupByDate = false): array
    {
        $results = $this->where('statamic_id', $id)
            ->where('date', '>=', $dateStart)
            ->where('available', '>=', $quantity)
            ->when($advanced, function ($query) use ($advanced) {
                if (! in_array('any', $advanced)) {
                    return $query->whereIn('rate_id', $advanced);
                }
            })
            ->orderBy('date')
            ->orderBy('price')
            ->get(['date', 'available', 'price', 'rate_id']);

        $formatDate = fn ($date) => \Carbon\Carbon::parse($date)->format('Y-m-d');

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
            ->sortBy(fn ($items) => $items->first()->price->format())
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
}

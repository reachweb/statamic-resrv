<?php

namespace Reach\StatamicResrv\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Events\AvailabilityChanged;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Resources\AvailabilityItemResource;
use Reach\StatamicResrv\Resources\AvailabilityResource;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Traits\HandlesPricing;
use Statamic\Facades\Blueprint;

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
        'property',
        'pending',
    ];

    protected $casts = [
        'price' => PriceClass::class,
        'pending' => 'array',
    ];

    protected $dispatchesEvents = [
        'updated' => AvailabilityChanged::class,
    ];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'statamic_id', 'item_id');
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    public function getPropertyLabel($handle, $collection, $slug)
    {
        $blueprint = Blueprint::find('collections.'.$collection.'.'.$handle);
        if (! AvailabilityField::blueprintHasAvailabilityField($blueprint)) {
            return false;
        }
        $field = AvailabilityField::getField($blueprint);
        $properties = $field->get('advanced_availability');

        if (array_key_exists($slug, $properties)) {
            return $properties[$slug];
        }

        return $slug;
    }

    public function getProperties(): array
    {
        if (! $field = $this->entry->getAvailabilityField()) {
            return [];
        }

        return Cache::rememberForever('properties:'.$this->entry->collection.':'.$this->entry->handle, function () use ($field) {
            return $field->get('advanced_availability');
        });
    }

    public function getConnectedAvailabilitySettings(): Collection|bool
    {
        if (! $field = $this->entry->getAvailabilityField()) {
            return false;
        }

        return Cache::rememberForever('connected_availability_'.$this->entry->collection.$this->entry->handle, function () use ($field) {
            return collect([
                'connected_availabilities' => $field->get('connected_availabilities'),
                'disable_connected_availabilities_on_cp' => $field->get('disable_connected_availabilities_on_cp'),
            ]);
        });
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

        if ($availability['message']['status'] != 1) {
            return false;
        }

        return true;
    }

    public function confirmAvailabilityAndPrice($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItemCollection($statamic_id)->resolve();

        if ($availability['message']['status'] != 1) {
            return false;
        }

        if ($availability['data']['price'] != $data['price']) {
            return false;
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
            'original_price' => $prices['originalPrice'] ? $prices['originalPrice']->format() : null,
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
        ];
    }

    protected function getResultsForItem(Entry $entry, $property = null)
    {
        return AvailabilityRepository::itemAvailableBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: $this->quantity,
            statamic_id: $entry->id(),
            advanced: $property ?? $this->advanced
        )
            ->get();
    }

    public function decrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced)
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
        ]);

        AvailabilityRepository::decrement(
            date_start: $this->date_start,
            date_end: $this->date_end,
            quantity: $this->quantity,
            statamic_id: $statamic_id,
            advanced: $this->advanced,
            reservationId: $reservationId
        );
    }

    public function incrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced)
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
        ]);

        AvailabilityRepository::increment(date_start: $this->date_start,
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
            return $this->getMultiplePropertiesAvailability($resrvEntry, $request);
        }

        $results = $this->getResultsForItem($resrvEntry)->first();

        if (! $results) {
            return new AvailabilityItemResource($availability, $request);
        }

        $availability->push($this->populateAvailability($results));

        return new AvailabilityItemResource($availability, $request);
    }

    protected function getMultiplePropertiesAvailability(Entry $entry, $request)
    {
        $availability = collect();

        $properties = AvailabilityRepository::itemGetProperties($entry->id())->pluck('property');

        foreach ($properties as $property) {
            $this->advanced = $property;

            $results = $this->getResultsForItem($entry, [$property])->first();

            if ($results) {
                $propertyLabel = cache()->remember($property.'_availability_label', 60, function () use ($entry, $property) {
                    if ($field = $entry->getAvailabilityField()) {
                        return $field->get('advanced_availability')[$property] ?? $property;
                    }
                });
                $availability->put($property, $this->populateAvailability($results, $propertyLabel));
            }
        }

        return new AvailabilityItemResource($availability->sortBy('price'), $request);
    }

    protected function populateAvailability($results, $label = null)
    {
        $prices = $this->getPrices($results->prices, $results->statamic_id);

        return collect([
            'price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice'] ? $prices['originalPrice']->format() : null,
            'payment' => $this->calculatePayment($prices['reservationPrice'])->format(),
            'property' => $results->property,
            'propertyLabel' => $label,
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

        if ($results->count() == 0) {
            return collect([]);
        }

        $disabled = array_flip($this->getDisabledIds());

        return $results->reject(function ($item) use ($disabled) {
            return isset($disabled[$item->statamic_id]);
        });
    }

    public function getPriceForDates($item, $id)
    {
        $prices = $this->getPrices($item->prices, $id);

        return [
            'reservation_price' => $prices['reservationPrice']->format(),
            'original_price' => $prices['originalPrice'] ? $prices['originalPrice']->format() : null,
        ];
    }

    // TODO: check where this is used
    protected function getProperty($results, $statamic_id, $singleItem = false)
    {
        if (! $singleItem) {
            $results = $results->where('statamic_id', $statamic_id);
        }
        // if (! ($results->first() instanceof AdvancedAvailability)) {
        //     return false;
        // }
        $property = $results->first()->property;
        if (! $results->every(fn ($item) => $item->property == $property)) {
            return false;
        }

        return $property;
    }

    protected function getDisabledIds()
    {
        $results = Entry::getDisabledIds();

        return Arr::flatten($results);
    }

    protected function getPeriod()
    {
        return CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE);
    }

    protected function createPricesCollection($prices)
    {
        return collect(explode(',', $prices))->transform(fn ($price) => Price::create($price));
    }

    protected function requestCollection($label = null): Collection
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
            'advanced' => $reservation->property,
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

    public function getAvailabilityCalendar(string $id, ?string $advanced): array
    {
        return $this->where('statamic_id', $id)
            ->where('date', '>=', now()->startOfDay()->toDateString())
            ->when($advanced, function ($query) use ($advanced) {
                return $query->where('property', $advanced);
            })
            ->get(['date', 'available', 'price', 'property'])
            ->groupBy('date')
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
                    return $query->whereIn('property', $advanced);
                }
            })
            ->orderBy('date')
            ->orderBy('price')
            ->get(['date', 'available', 'price', 'property']);

        $formatDate = fn ($date) => \Carbon\Carbon::parse($date)->format('Y-m-d');

        if ($groupByDate) {
            return $results
                ->groupBy(fn ($item) => $formatDate($item->date))
                ->map(function ($items) {
                    // Items already sorted by price from query
                    return $items->mapWithKeys(function ($item) {
                        return [
                            $item->property => [
                                'available' => $item->available,
                                'price' => $item->price->format(),
                            ],
                        ];
                    })->toArray();
                })
                ->toArray();
        }

        return $results
            ->groupBy('property')
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

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
        if (! $blueprint->hasField('resrv_availability')) {
            return false;
        }
        $properties = $blueprint->field('resrv_availability')->get('advanced_availability');

        if (array_key_exists($slug, $properties)) {
            return $properties[$slug];
        }

        return $slug;
    }

    public function getConnectedAvailabilitySetting()
    {
        $blueprint = $this->entry->getStatamicEntry()->blueprint();

        return Cache::rememberForever('connected_availability_'.$blueprint->namespace(), function () use ($blueprint) {
            if (! $blueprint->hasField('resrv_availability')) {
                return false;
            }

            return $blueprint->field('resrv_availability')->get('connected_availabilities');
        });
    }

    public function getConnectedAvailabilityManualSetting()
    {
        $blueprint = $this->entry->getStatamicEntry()->blueprint();

        return Cache::rememberForever('connected_availability_manual_setting_'.$blueprint->namespace(), function () use ($blueprint) {
            return $blueprint->field('resrv_availability')->get('manual_connected_availabilities');
        });
    }

    public function getAvailable($data)
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getAvailabilityCollection()->resolve();
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

    protected function getResultsForItem($entry, $property = null)
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

    protected function getAvailabilityCollection()
    {
        $request = $this->requestCollection();

        $availableWithPricing = $this->availableForDates()
            ->groupBy('statamic_id')
            ->map(function ($items) {
                return $items->map(fn ($item) => $this->populateAvailability($item))
                    ->sortBy('price', SORT_NUMERIC);
            });

        return new AvailabilityResource($availableWithPricing, $request);
    }

    protected function getSpecificItemCollection($statamic_id)
    {
        $entry = $this->getDefaultSiteEntry($statamic_id);

        $request = $this->requestCollection();

        $availability = collect();

        if ($entry->get('resrv_availability') == 'disabled') {
            return new AvailabilityItemResource($availability, $request);
        }

        if ($this->advanced && in_array('any', $this->advanced)) {
            return $this->getMultiplePropertiesAvailability($entry, $request);
        }

        $results = $this->getResultsForItem($entry)->first();

        if (! $results) {
            return new AvailabilityItemResource($availability, $request);
        }

        $availability->push($this->populateAvailability($results));

        return new AvailabilityItemResource($availability, $request);
    }

    protected function getMultiplePropertiesAvailability($entry, $request)
    {
        $availability = collect();

        $properties = AvailabilityRepository::itemGetProperties($entry->id())->pluck('property');

        foreach ($properties as $property) {
            $this->advanced = $property;

            $results = $this->getResultsForItem($entry, [$property])->first();

            if ($results) {
                $propertyLabel = cache()->remember($property.'_availability_label', 60, function () use ($entry, $property) {
                    if ($field = $entry->blueprint()->field('resrv_availability')) {
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
        $results = Entry::where('enabled', 0)
            ->get('item_id')
            ->toArray();

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
}

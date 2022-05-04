<?php

namespace Reach\StatamicResrv\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Database\Factories\AvailabilityFactory;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Reach\StatamicResrv\Traits\HandlesPricing;
use Statamic\Facades\Entry;

class Availability extends Model implements AvailabilityContract
{
    use HasFactory, HandlesAvailabilityDates, HandlesPricing, HandlesMultisiteIds;

    protected $table = 'resrv_availabilities';

    protected $fillable = ['statamic_id', 'date', 'price', 'available'];

    protected $casts = [
        'price' => PriceClass::class,
    ];

    protected static function newFactory()
    {
        return AvailabilityFactory::new();
    }

    public function scopeEntry($query, $entry)
    {
        return $query->where('statamic_id', $entry);
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    public function getAvailableItems($data)
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getAllAvailableItems();
    }

    public function getMultipleAvailableItems($data)
    {
        ExpireReservations::dispatchSync();

        return $this->getMultiple($data);
    }

    public function getAvailabilityForItem($data, $statamic_id)
    {
        ExpireReservations::dispatchSync();

        $this->initiateAvailability($data);

        return $this->getSpecificItem($statamic_id);
    }

    public function getMultipleAvailabilityForItem($data, $statamic_id)
    {
        ExpireReservations::dispatchSync();

        return $this->getMultiSpecificItem($data, $statamic_id);
    }

    public function confirmAvailability($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItem($statamic_id);

        if ($availability['message']['status'] != 1) {
            return false;
        }

        return true;
    }

    public function confirmAvailabilityAndPrice($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $availability = $this->getSpecificItem($statamic_id);

        if ($availability['message']['status'] != 1) {
            return false;
        }

        if ($availability['data']['price'] != $data['price']) {
            return false;
        }

        return true;
    }

    public function getPriceForItem($data, $statamic_id)
    {
        $this->initiateAvailability($data);

        $entry = $this->getDefaultSiteEntry($statamic_id);

        $results = $this->getResultsForItem($entry);

        $this->calculatePrice($results, $entry->id());

        return $this->reservation_price;
    }

    protected function getResultsForItem($entry)
    {
        return AvailabilityRepository::itemAvailableBetween($this->date_start, $this->date_end, $this->quantity, $this->advanced, $entry->id())
            ->get(['date', 'price', 'available', 'property'])
            ->sortBy('date');
    }

    public function decrementAvailability($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
        ]);

        AvailabilityRepository::decrement($this->date_start, $this->date_end, $this->quantity, $this->advanced, $statamic_id);
    }

    public function incrementAvailability($date_start, $date_end, $quantity, $advanced, $statamic_id)
    {
        $this->initiateAvailabilityUnsafe([
            'date_start' => $date_start,
            'date_end' => $date_end,
            'quantity' => $quantity,
            'advanced' => $advanced,
        ]);

        AvailabilityRepository::increment($this->date_start, $this->date_end, $this->quantity, $this->advanced, $statamic_id);
    }

    protected function getAllAvailableItems()
    {
        $availableWithPricing = [];
        $available = $this->availableForDates();
        $results = AvailabilityRepository::priceForDates($this->date_start, $this->date_end, $this->advanced)->get();

        foreach ($available as $id) {
            $id = $this->getDefaultSiteEntry($id)->id();
            $price = $this->getPriceForDates($results, $id);
            $property = $this->getProperty($results, $id);
            $availableWithPricing[$id] = $this->buildItemsArray($id, $price, $property);

            $multisiteIds = $this->getMultisiteIds($id);
            if (count($multisiteIds) > 0) {
                $availableWithPricing[$id]['multisite_ids'] = $multisiteIds;
            }
        }

        return $availableWithPricing;
    }

    protected function getMultiple($data)
    {
        $available = [];
        foreach ($data['dates'] as $dates) {
            $this->initiateAvailability($dates);
            $available[] = $this->availableForDates();
        }
        $available = array_intersect(...array_values($available));

        $availableWithPricing = [];

        foreach ($available as $id) {
            $price = Price::create(0);
            $id = $this->getDefaultSiteEntry($id)->id();
            foreach ($data['dates'] as $dates) {
                $this->initiateAvailability($dates);
                $results = AvailabilityRepository::priceForDates($this->date_start, $this->date_end, $this->advanced)->get();
                $price->add($this->getPriceForDates($results, $id)['reservation_price']);
                $property = $this->getProperty($results, $id);
            }
            $availableWithPricing[$id] = $this->buildMultiItemsArray($id, $price, $property);
            $multisiteIds = $this->getMultisiteIds($id);
            if (count($multisiteIds) > 0) {
                $availableWithPricing[$id]['multisite_ids'] = $multisiteIds;
            }
        }

        return $availableWithPricing;
    }

    protected function getSpecificItem($statamic_id)
    {
        $entry = $this->getDefaultSiteEntry($statamic_id);

        $results = $this->getResultsForItem($entry);

        $entryAvailabilityValue = $entry->get('resrv_availability');

        if (($results->count() !== count($this->getPeriod())) || $entryAvailabilityValue == 'disabled') {
            return [
                'message' => [
                    'status' => false,
                ],
            ];
        }

        $this->calculatePrice($results, $entry->id());
        $property = $this->getProperty($results, $entry->id(), true);
        
        return $this->buildSpecificItemArray($property);
    }

    protected function getMultiSpecificItem($data, $statamic_id)
    {
        $entry = $this->getDefaultSiteEntry($statamic_id);
        $entryAvailabilityValue = $entry->get('resrv_availability');

        $results = collect();
        $days = collect();
        foreach ($data['dates'] as $dates) {
            $this->initiateAvailability($dates);
            $days = $days->push($this->duration);
            $resultsForDates = $this->getResultsForItem($entry);
            if ($resultsForDates->count() !== count($this->getPeriod()) || $entryAvailabilityValue == 'disabled') {
                return [
                    'message' => [
                        'status' => false,
                    ],
                ];
            }
            $results = $results->merge($this->getResultsForItem($entry));
        }

        $this->calculatePrice($results, $entry->id());
        $property = $this->getProperty($results, $entry->id(), true);

        return $this->buildMultiSpecificItemArray($days, $property);
    }

    protected function buildSpecificItemArray($property)
    {
        return [
            'request' => [
                'days' => $this->duration,
                'date_start' => $this->date_start,
                'date_end' => $this->date_end,
                'quantity' => $this->quantity,
            ],
            'data' => [
                'price' => $this->reservation_price->format(),
                'payment' => $this->calculatePayment($this->reservation_price)->format(),
                'original_price' => (isset($this->original_price) ? $this->original_price : null),
                'property' => $property ?? null,
            ],
            'message' => [
                'status' => 1,
            ],
        ];
    }

    protected function buildItemsArray($id, $price, $property)
    {
        return [
            'id' => $id,
            'request' => [
                'days' => $this->duration,
                'date_start' => $this->date_start,
                'date_end' => $this->date_end,
                'quantity' => $this->quantity,
            ],
            'data' => [
                'price' => $price['reservation_price']->format(),
                'payment' => $this->calculatePayment($price)->format(),
                'original_price' => ($price['original_price'] ?? null),
                'property' => $property ?? null,
            ],
            'message' => [
                'status' => 1,
            ],
        ];
    }

    protected function buildMultiSpecificItemArray($days, $property)
    {
        return [
            'request' => [
                'days' => $days->sum(),
            ],
            'data' => [
                'price' => $this->reservation_price->format(),
                'payment' => $this->calculatePayment($this->reservation_price)->format(),
                'original_price' => (isset($this->original_price) ? $this->original_price : null),
                'property' => $property ?? null,
            ],
            'message' => [
                'status' => 1,
            ],
        ];
    }

    protected function buildMultiItemsArray($id, $price, $property)
    {
        return [
            'id' => $id,
            'data' => [
                'price' => $price->format(),
                'payment' => $this->calculatePayment($price)->format(),
                'original_price' => (isset($this->original_price) ? $this->original_price : null),
                'property' => $property ?? null,
            ],
            'message' => [
                'status' => 1,
            ],
        ];
    }

    /**
     * Search for availability entries between the dates and then return the ids
     * of the items that have at least 1 available for each day.
     */
    protected function availableForDates()
    {
        $results = AvailabilityRepository::availableBetween($this->date_start, $this->date_end, $this->quantity, $this->advanced)->get();

        $idsFound = $results->groupBy('statamic_id')->keys();

        $days = [];
        foreach ($idsFound as $id) {
            $dates = $results->where('statamic_id', $id)->sortBy('date');
            // If the count of the dates is not the same like the period, it usually
            // means that a date has no availability information, so we should just skip
            if ($dates->count() !== count($this->getPeriod())) {
                continue;
            }
            foreach ($dates as $availability) {
                $days[$availability->date][] = $id;
            }
        }

        if (count($days) == 0) {
            return [];
        }

        $disabled = $this->getDisabledIds();
        $available = array_intersect(...array_values($days));

        return array_diff($available, $disabled);
    }


    public function getPriceForDates($results, $statamic_id)
    {
        $results = $results->where('statamic_id', $statamic_id);

        $this->calculatePrice($results, $statamic_id);

        return [
            'reservation_price' => $this->reservation_price,
            'original_price' => $this->original_price ?? null,
        ];
    }

    protected function getProperty($results, $statamic_id, $singleItem = false)
    {
        if (! $singleItem) {
            $results = $results->where('statamic_id', $statamic_id);
        }
        if (! ($results->first() instanceof AdvancedAvailability)) {
            return false;
        }
        $property = $results->first()->property;
        if (! $results->every(fn ($item) => $item->property == $property )) {
            return false;
        }
        return $property;
    }

    protected function getDisabledIds()
    {
        $results = Entry::query()
            ->where('resrv_availability', 'disabled')
            ->where('published', true)
            ->get()
            ->toAugmentedArray('id');

        return array_flatten($results);
    }

    protected function getPeriod()
    {
        return CarbonPeriod::create($this->date_start, $this->date_end, CarbonPeriod::EXCLUDE_END_DATE);
    }

    protected function calculatePayment($price): PriceClass
    {
        if (is_array($price)) {
            $price = $price['reservation_price'];
        }
        if (config('resrv-config.payment', 'full') == 'full') {
            return $price;
        }
        if (config('resrv-config.payment') == 'fixed') {
            return Price::create(config('resrv-config.fixed_amount'));
        }
        if (config('resrv-config.payment') == 'percent') {
            return $price->percent(config('resrv-config.percent_amount'));
        }
    }
}

<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\CutoffException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\EntryCollection;
use Statamic\Extensions\Pagination\LengthAwarePaginator;

trait HandlesAvailabilityQueries
{
    use HandlesCutoffValidation, HandlesMultisiteIds;

    public function getAvailability(Collection $data, EntryCollection|LengthAwarePaginator|null $entries = null): array
    {
        $searchData = $this->toResrvArray($data->first());

        try {
            $entryIds = $entries ? $this->getEntryIds($entries) : null;

            return app(Availability::class)->getAvailable($searchData, $entryIds);
        } catch (AvailabilityException $exception) {
            return [
                'message' => [
                    'status' => false,
                    'error' => $exception->getMessage(),
                ],
                'request' => $searchData,
            ];
        }
    }

    protected function getEntryIds(EntryCollection|LengthAwarePaginator $entries): array
    {
        if ($entries instanceof LengthAwarePaginator) {
            return collect($entries->items())->pluck('id')->toArray();
        }

        return $entries->pluck('id')->toArray();
    }

    public function queryBaseAvailabilityForEntry(): array
    {
        try {
            return app(Availability::class)->getAvailabilityForEntry($this->data->toResrvArray(), $this->entryId);
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());

            return [];
        }
    }

    public function queryExtraAvailabilityForEntry(): Collection
    {
        $periods = $this->generateDatePeriods($this->data);

        $periods->transform(function ($period) {
            $searchData = array_merge($period, Arr::only($this->data->toResrvArray(), ['quantity', 'advanced']));
            try {
                $this->validateCutoffRules($searchData['date_start']);

                return app(Availability::class)->getAvailabilityForEntry($searchData, $this->entryId);
            } catch (AvailabilityException|CutoffException $exception) {
                return [
                    'message' => [
                        'status' => false,
                        'error' => $exception->getMessage(),
                    ],
                    'request' => $searchData,
                ];
            }
        });

        return $periods;
    }

    public function queryAvailabilityForAllProperties(): Collection
    {
        return collect($this->advancedProperties)->keys()->mapWithKeys(function ($property) {
            $searchData = array_merge($this->data->toResrvArray(), ['advanced' => $property]);
            try {
                return [$property => app(Availability::class)->getAvailabilityForEntry($searchData, $this->entryId)];
            } catch (AvailabilityException $exception) {
                return [
                    $property => [
                        'message' => [
                            'status' => false,
                            'error' => $exception->getMessage(),
                        ],
                        'request' => $searchData,
                    ],
                ];
            }
        });

    }

    public function validateAvailabilityAndPrice()
    {
        $searchData = array_merge(['price' => data_get($this->availability, 'data.price')], $this->data->toResrvArray());
        if (app(Availability::class)->confirmAvailabilityAndPrice($searchData, $this->entryId) === false) {
            throw new AvailabilityException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }
    }

    protected function generateDatePeriods(): Collection
    {
        $dateStart = Carbon::parse($this->data->dates['date_start']);
        $dateEnd = Carbon::parse($this->data->dates['date_end']);

        $datePeriods = collect([]);

        for ($i = 1; $i <= $this->extraDays; $i++) {
            $beforeStart = $dateStart->copy()->subDays($i + ($i * $this->extraDaysOffset));
            $beforeEnd = $dateEnd->copy()->subDays($i + ($i * $this->extraDaysOffset));
            $datePeriods->put('-'.$i, [
                'date_start' => $beforeStart,
                'date_end' => $beforeEnd,
            ]);

            $afterStart = $dateStart->copy()->addDays($i + ($i * $this->extraDaysOffset));
            $afterEnd = $dateEnd->copy()->addDays($i + ($i * $this->extraDaysOffset));
            $datePeriods->put('+'.$i, [
                'date_start' => $afterStart,
                'date_end' => $afterEnd,
            ]);
        }

        $datePeriods->put(0, [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ]);

        return $datePeriods->sortKeys();
    }

    public function availabilitySearchData($values): Collection
    {
        return collect($values)->filter(function ($value, $key) {
            return Str::startsWith($key, 'resrv_search:');
        })->reject(fn ($value) => empty($value) || ! Arr::has($value, 'dates.date_start'));
    }

    public function toResrvArray($search)
    {
        return [
            'date_start' => $search['dates']['date_start'],
            'date_end' => $search['dates']['date_end'],
            'quantity' => $search['quantity'] ?? 1,
            'advanced' => $search['advanced'] ?? '',
        ];
    }

    public function getAvailabilityCalendar(): array
    {
        if (! $this->entry) {
            throw new AvailabilityException(__('You need to provide an entry ID to enable the availability calendar.'));
        }

        $entry = $this->getDefaultSiteEntry($this->entry)->id();

        return app(Availability::class)->getAvailabilityCalendar($entry, $this->advanced && $this->data->advanced ? $this->data->advanced : null);
    }
}

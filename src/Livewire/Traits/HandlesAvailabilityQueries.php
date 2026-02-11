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
        $items = $entries instanceof LengthAwarePaginator
            ? collect($entries->items())
            : $entries;

        return $items->map(
            fn ($entry) => $entry->hasOrigin() ? $entry->origin()->id() : $entry->id()
        )->toArray();
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
        return $this->generateDatePeriods($this->data)->map(function ($period) {
            $searchData = array_merge($period, Arr::only($this->data->toResrvArray(), ['quantity', 'rate_id', 'advanced']));
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
    }

    public function queryAvailabilityForAllRates(): Collection
    {
        return collect($this->entryRates)->keys()->mapWithKeys(function ($rateKey) {
            $searchData = array_merge($this->data->toResrvArray(), ['advanced' => $rateKey, 'rate_id' => $rateKey]);
            try {
                return [$rateKey => app(Availability::class)->getAvailabilityForEntry($searchData, $this->entryId)];
            } catch (AvailabilityException $exception) {
                return [
                    $rateKey => [
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

    public function validateAvailabilityAndPrice(): void
    {
        $searchData = array_merge(['price' => data_get($this->availability, 'data.price')], $this->data->toResrvArray());

        if (! app(Availability::class)->confirmAvailabilityAndPrice($searchData, $this->entryId)) {
            throw new AvailabilityException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }
    }

    protected function generateDatePeriods(): Collection
    {
        $dateStart = Carbon::parse($this->data->dates['date_start']);
        $dateEnd = Carbon::parse($this->data->dates['date_end']);

        $datePeriods = collect();

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
        return collect($values)
            ->filter(fn ($value, $key) => Str::startsWith($key, 'resrv_search:'))
            ->reject(fn ($value) => empty($value) || ! Arr::has($value, 'dates.date_start'));
    }

    public function toResrvArray($search): array
    {
        return [
            'date_start' => $search['dates']['date_start'],
            'date_end' => $search['dates']['date_end'],
            'quantity' => $search['quantity'] ?? 1,
            'rate_id' => $search['rate_id'] ?? null,
            'advanced' => $search['advanced'] ?? '',
        ];
    }

    public function getAvailabilityCalendar(): array
    {
        if (! $this->entry) {
            throw new AvailabilityException(__('You need to provide an entry ID to enable the availability calendar.'));
        }

        $entry = $this->getDefaultSiteEntry($this->entry)->id();

        $rateId = ($this->rates && $this->data->rate) ? $this->data->rate : null;

        return app(Availability::class)->getAvailabilityCalendar($entry, $rateId);
    }

    public function queryAvailableDatesFromDate(): array
    {
        if (! isset($this->data->dates['date_start'])) {
            return [];
        }

        $dateStart = Carbon::parse($this->data->dates['date_start'])->format('Y-m-d');

        $rateId = $this->data->rate ? [$this->data->rate] : ($this->rates ? ['any'] : null);

        return app(Availability::class)->getAvailableDatesFromDate(
            $this->entryId,
            $dateStart,
            $this->data->quantity ?? 1,
            $rateId,
            $this->groupByDate
        );
    }
}

<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;

trait HandlesAvailabilityQueries
{
    public function getAvailability(Collection $data): array
    {
        try {
            return (new Availability)->getAvailable($this->toResrvArray($data->first()));
        } catch (AvailabilityException $exception) {
            return [
                'message' => [
                    'status' => false,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function queryBaseAvailabilityForEntry(): array
    {
        try {
            return (new Availability)->getAvailabilityForEntry($this->data->toResrvArray(), $this->entryId);
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
                return (new Availability)->getAvailabilityForEntry($searchData, $this->entryId);
            } catch (AvailabilityException $exception) {
                return [
                    'message' => [
                        'status' => false,
                        'error' => $exception->getMessage(),
                    ],
                ];
            }
        });

        return $periods;
    }

    public function validateAvailabilityAndPrice()
    {
        $searchData = array_merge(['price' => data_get($this->availability, 'data.price')], $this->data->toResrvArray());
        if ((new Availability)->confirmAvailabilityAndPrice($searchData, $this->entryId) === false) {
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
}

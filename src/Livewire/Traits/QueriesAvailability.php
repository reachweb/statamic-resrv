<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;

trait QueriesAvailability
{
    public function queryBaseAvailability(): array
    {
        try {
            return (new Availability)->getAvailabilityForItem($this->data->toResrvArray(), $this->entryId);
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    public function queryExtraAvailability(): Collection
    {
        $periods = $this->generateDatePeriods($this->data);

        $periods->transform(function ($period) {
            $searchData = array_merge($period, Arr::only($this->data->toResrvArray(), ['quantity', 'property']));
            try {
                return (new Availability)->getAvailabilityForItem($searchData, $this->entryId);
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

    public function generateDatePeriods(): Collection
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
}

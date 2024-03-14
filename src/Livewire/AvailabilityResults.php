<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;

class AvailabilityResults extends Component
{
    use HandlesMultisiteIds;

    public string $entryId;

    public Collection $availability;

    #[Locked]
    public int $extraDays = 0;

    #[Locked]
    public int $extraDaysOffset = 0;

    public function mount(string $entry)
    {
        $this->entryId = $this->getDefaultSiteEntry($entry)->id();
        $this->availability = collect();
    }

    #[On('availability-search-updated')]
    public function getAvailability($data): void
    {
        if ($this->extraDays === 0) {
            $this->availability = collect($this->queryBaseAvailability($data));

            return;
        }
        if ($this->extraDays > 0) {
            $this->availability = $this->queryExtraAvailability($data);

            return;
        }
    }

    public function queryBaseAvailability($data): array
    {
        try {
            return (new Availability)->getAvailabilityForItem($data, $this->entryId);
        } catch (AvailabilityException $exception) {
            $this->addError('availability', $exception->getMessage());
        }
    }

    public function queryExtraAvailability($data): Collection
    {
        $periods = $this->generateDatePeriods($data);
        $periods->transform(function ($period) use ($data) {
            $searchData = array_merge($period, Arr::only($data, ['quantity', 'property']));
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

    public function generateDatePeriods($data): Collection
    {
        $dateStart = Carbon::parse($data['date_start']);
        $dateEnd = Carbon::parse($data['date_end']);

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

    public function render()
    {
        return view('statamic-resrv::livewire.availability-results');
    }
}

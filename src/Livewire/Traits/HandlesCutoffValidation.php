<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Reach\StatamicResrv\Exceptions\CutoffException;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;

trait HandlesCutoffValidation
{
    public function validateCutoffRules($date = null): void
    {
        if (! config('resrv-config.enable_cutoff_rules', false)) {
            return;
        }

        $resrvEntry = ResrvEntry::whereItemId($this->entryId);

        $dateStart = $date ?? $this->data->dates['date_start'];
        $schedule = $resrvEntry->getCutoffScheduleForDate($dateStart);

        if (! $schedule) {
            return; // No schedule found
        }
        if ($this->isWithinCutoffWindow($schedule, $dateStart)) {
            throw new CutoffException(__('statamic-resrv::frontend.cutoffTimeExceeded', [
                'hours' => $schedule['cutoff_hours'],
                'time' => $schedule['starting_time'],
            ]));
        }

    }

    protected function isWithinCutoffWindow(array $schedule, string $dateStart): bool
    {
        $reservationDate = Carbon::parse($dateStart);

        // Create the starting datetime by combining date and time
        $startingDateTime = Carbon::parse($reservationDate->format('Y-m-d').' '.$schedule['starting_time']);

        // Calculate cutoff time
        $cutoffTime = $startingDateTime->copy()->subHours($schedule['cutoff_hours']);

        // Check if current time is past the cutoff
        return Carbon::now()->greaterThan($cutoffTime);
    }
}

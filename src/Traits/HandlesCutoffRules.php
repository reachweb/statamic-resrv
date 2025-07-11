<?php

namespace Reach\StatamicResrv\Traits;

use Carbon\Carbon;

trait HandlesCutoffRules
{
    public function getCutoffRules(): ?array
    {
        return $this->options['cutoff_rules'] ?? null;
    }

    public function hasCutoffRules(): bool
    {
        if (! config('resrv-config.enable_cutoff_rules', false)) {
            return false;
        }

        return isset($this->options['cutoff_rules']['enable_cutoff'])
            && $this->options['cutoff_rules']['enable_cutoff'] === true;
    }

    public function getCutoffScheduleForDate(string $date): ?array
    {
        if (! $this->hasCutoffRules()) {
            return null;
        }

        $rules = $this->getCutoffRules();

        if (isset($rules['schedules'])) {
            foreach ($rules['schedules'] as $schedule) {
                if ($this->isDateInRange($date, $schedule['date_start'], $schedule['date_end'])) {
                    return [
                        'starting_time' => $schedule['starting_time'],
                        'cutoff_hours' => $schedule['cutoff_hours'],
                        'schedule_name' => $schedule['name'] ?? 'Schedule',
                    ];
                }
            }
        }

        return [
            'starting_time' => $rules['default_starting_time'] ?? '16:00',
            'cutoff_hours' => $rules['default_cutoff_hours'] ?? 3,
            'schedule_name' => 'Default Schedule',
        ];
    }

    private function isDateInRange(string $date, string $startDate, string $endDate): bool
    {
        $checkDate = Carbon::parse($date);
        $rangeStart = Carbon::parse($startDate);
        $rangeEnd = Carbon::parse($endDate);

        return $checkDate->between($rangeStart, $rangeEnd, true);
    }
}

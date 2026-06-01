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

        // No schedule matched. Only fall back to a default when one is actually configured;
        // otherwise return null so callers treat it as "no cutoff applies" instead of computing
        // a bogus midnight cutoff from a null starting_time and throwing a false CutoffException.
        if (empty($rules['default_starting_time'])) {
            return null;
        }

        return [
            'starting_time' => $rules['default_starting_time'],
            'cutoff_hours' => $rules['default_cutoff_hours'] ?? null,
            'schedule_name' => 'Default Schedule',
        ];
    }

    private function isDateInRange(string $date, string $startDate, string $endDate): bool
    {
        $checkDate = Carbon::parse($date)->startOfDay();
        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->startOfDay();

        return $checkDate->between($rangeStart, $rangeEnd, true);
    }
}

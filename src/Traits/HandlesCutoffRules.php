<?php

namespace Reach\StatamicResrv\Traits;

trait HandlesCutoffRules
{
    public function getCutoffRules(): ?array
    {
        return $this->options['cutoff_rules'] ?? null;
    }

    public function hasCutoffRules(): bool
    {
        // Check if cutoff rules are globally enabled first
        if (! config('statamic.resrv.enable_cutoff_rules', false)) {
            return false;
        }

        return isset($this->options['cutoff_rules']['enable_cutoff'])
            && $this->options['cutoff_rules']['enable_cutoff'] === true;
    }

    /**
     * Get the cutoff schedule (starting time and cutoff hours) for a specific date
     */
    public function getCutoffScheduleForDate(string $date): ?array
    {
        if (! $this->hasCutoffRules()) {
            return null;
        }

        $rules = $this->getCutoffRules();

        // Check schedules first
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

        // Fall back to default
        return [
            'starting_time' => $rules['default_starting_time'] ?? '16:00',
            'cutoff_hours' => $rules['default_cutoff_hours'] ?? 3,
            'schedule_name' => 'Default Schedule',
        ];
    }

    /**
     * Check if a date falls within a given range
     */
    private function isDateInRange(string $date, string $startDate, string $endDate): bool
    {
        return $date >= $startDate && $date <= $endDate;
    }
}

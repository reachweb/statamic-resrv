<?php

namespace Reach\StatamicResrv\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Reach\StatamicResrv\Models\Entry;

class ResrvCutoffTime implements DataAwareRule, ValidationRule
{
    protected $data = [];

    protected $entryId;

    public function __construct(?string $entryId = null)
    {
        $this->entryId = $entryId;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if cutoff rules are globally enabled
        if (! config('statamic.resrv.enable_cutoff_rules', false)) {
            return; // Cutoff rules are disabled globally
        }

        $entryId = $this->entryId ?? ($this->data['statamic_id'] ?? null);

        if (! $entryId) {
            return; // No entry ID, skip validation
        }

        try {
            $resrvEntry = Entry::whereItemId($entryId);

            if (! $resrvEntry->hasCutoffRules()) {
                return; // No cutoff rules configured
            }

            $dateStart = $this->data['date_start'] ?? $value;
            $schedule = $resrvEntry->getCutoffScheduleForDate(Carbon::parse($dateStart)->format('Y-m-d'));

            if (! $schedule) {
                return; // No schedule found
            }

            if ($this->isWithinCutoffWindow($schedule, $dateStart)) {
                $fail(__('Reservations for this time must be made at least :hours hours before the starting time (:time). Please select a date from tomorrow onwards.', [
                    'hours' => $schedule['cutoff_hours'],
                    'time' => $schedule['starting_time'],
                ]));
            }

        } catch (\Exception $e) {
            // If there's any error, don't block the reservation
            return;
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

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}

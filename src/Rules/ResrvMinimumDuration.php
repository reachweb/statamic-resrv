<?php

namespace Reach\StatamicResrv\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ResrvMinimumDuration implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $duration = (int) abs(Carbon::parse($value['date_start'])->startOfDay()->diffInDays(Carbon::parse($value['date_end'])->startOfDay()));

        if ($duration > config('resrv-config.maximum_reservation_period_in_days')) {
            $fail(__('The period you selected exceeds the maximum allowed reservation period.'));
        }
        if ($duration < config('resrv-config.minimum_reservation_period_in_days')) {
            $fail(__('The period you selected is smaller than the minimum allowed reservation period.'));
        }
    }
}

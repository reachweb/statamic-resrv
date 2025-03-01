<?php

namespace Reach\StatamicResrv\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ResrvMinimumDate implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (config('resrv-config.minimum_days_before') > 0) {
            // We do a double create to get rid of the user's timezone
            $value = Carbon::create($value);
            $date = Carbon::create($value->year, $value->month, $value->day, 0, 0, 0);

            if ((int) $date->diffInDays(Carbon::now()->startOfDay(), true) < config('resrv-config.minimum_days_before')) {
                $fail('The :attribute is closer than allowed.');
            }
        }
    }
}

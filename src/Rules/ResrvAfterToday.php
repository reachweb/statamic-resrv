<?php

namespace Reach\StatamicResrv\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ResrvAfterToday implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // We do a double create to get rid of the user's timezone
        $value = Carbon::create($value);
        $date = Carbon::create($value->year, $value->month, $value->day, 0, 0, 0);

        if (! $date->greaterThanOrEqualTo(Carbon::now()->startOfDay())) {
            $fail('The starting date must be today or later.');
        }
    }
}

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
        if (config('resrv-config.minimum_days_before')) {
            $value = Carbon::parse($value);
            if ($value->diffInDays(Carbon::now()->startOfDay()) < config('resrv-config.minimum_days_before')) {
                $fail('The :attribute is closer than allowed.');
            }
        }
    }
}

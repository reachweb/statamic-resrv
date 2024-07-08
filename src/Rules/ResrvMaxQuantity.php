<?php

namespace Reach\StatamicResrv\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ResrvMaxQuantity implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value > config('resrv-config.maximum_quantity')) {
            $fail(__('You cannot reserve these many in one reservation.'));
        }
    }
}

<?php

namespace Reach\StatamicResrv\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Reach\StatamicResrv\Facades\Availability;

class ResrvAvailabilityExists implements DataAwareRule, ValidationRule
{
    protected $data = [];

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $otherAttribute = $attribute === 'price' ? 'available' : 'price';

        if (! is_null($value) && is_null($this->data[$otherAttribute])) {
            if (array_key_exists('advanced', $this->data)) {
                foreach ($this->data['advanced'] as $property) {
                    if (! Availability::itemsExistAndHavePrices(
                        $this->data['date_start'],
                        $this->data['date_end'],
                        $this->data['statamic_id'],
                        [$property['code']]
                    )) {
                        $fail(__('The availability does not exist or does not have prices for the selected date range.'));
                    }
                }
            } else {
                if (! Availability::itemsExistAndHavePrices(
                    $this->data['date_start'],
                    $this->data['date_end'],
                    $this->data['statamic_id'],
                    ['none']
                )) {
                    $fail(__('The availability does not exist or does not have prices for the selected date range.'));
                }
            }
        }
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}

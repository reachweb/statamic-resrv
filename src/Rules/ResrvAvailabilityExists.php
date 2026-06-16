<?php

namespace Reach\StatamicResrv\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Reach\StatamicResrv\Facades\Availability;
use Reach\StatamicResrv\Models\Rate;

class ResrvAvailabilityExists implements DataAwareRule, ValidationRule
{
    protected $data = [];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $otherAttribute = $attribute === 'price' ? 'available' : 'price';

        if (! is_null($value) && is_null($this->data[$otherAttribute] ?? null)) {
            $onlyDays = $this->data['onlyDays'] ?? null;

            if (array_key_exists('rate_ids', $this->data)) {
                foreach ($this->data['rate_ids'] as $rateId) {
                    if (! Availability::itemsExistAndHavePrices(
                        $this->data['date_start'],
                        $this->data['date_end'],
                        $this->data['statamic_id'],
                        (int) $rateId,
                        $onlyDays
                    )) {
                        $fail(__('The availability does not exist or does not have prices for the selected date range.'));
                    }
                }
            } else {
                // No rate_ids: the controller writes to the entry's default rate — the first
                // forEntry rate (AvailabilityCpController::defaultRateIds) — so the existence check
                // must be scoped to THAT rate. An unscoped check could be satisfied by a different
                // rate's rows and let a single-field edit create a partial row on the default rate
                // that has none. No resolvable default rate means the write would target a brand-new
                // rate with no priced rows, which a single-field edit cannot satisfy.
                $defaultRate = Rate::forEntry($this->data['statamic_id'])->first();

                if (! $defaultRate || ! Availability::itemsExistAndHavePrices(
                    $this->data['date_start'],
                    $this->data['date_end'],
                    $this->data['statamic_id'],
                    (int) $defaultRate->id,
                    $onlyDays
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

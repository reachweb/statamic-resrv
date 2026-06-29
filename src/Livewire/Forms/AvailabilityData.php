<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;
use Reach\StatamicResrv\Rules\ResrvAfterToday;
use Reach\StatamicResrv\Rules\ResrvMaxQuantity;
use Reach\StatamicResrv\Rules\ResrvMinimumDate;
use Reach\StatamicResrv\Rules\ResrvMinimumDuration;

class AvailabilityData extends Form
{
    public array $dates = [];

    public int $quantity = 1;

    public string|int|null $rate = null;

    public ?array $customer = [];

    public function rules(): array
    {
        return [
            'dates' => ['required', 'array', new ResrvMinimumDuration],
            'dates.date_start' => [
                'required',
                'date',
                'before:dates.date_end',
                new ResrvAfterToday,
                new ResrvMinimumDate,
            ],
            'dates.date_end' => [
                'required',
                'date',
                'after:dates.date_start',
                new ResrvAfterToday,
            ],
            'quantity' => ['sometimes', 'integer', 'min:1', new ResrvMaxQuantity],
            'rate' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'any' && ! is_numeric($value)) {
                    $fail('The rate must be a valid rate ID or "any".');
                }
            }],
            'customer' => ['sometimes', 'array'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'dates.date_start' => 'starting date',
            'dates.date_end' => 'ending date',
        ];
    }

    public function toResrvArray(): array
    {
        return [
            'date_start' => $this->dates['date_start'] ?? null,
            'date_end' => $this->dates['date_end'] ?? null,
            'quantity' => $this->quantity,
            'rate_id' => $this->rate,
        ];
    }

    /**
     * Reconcile the (context-scoped) rate against the rates valid for the component's
     * current context. Heals a stale/foreign rate carried in from the shared 'resrv-search'
     * session key, and auto-selects when exactly one rate exists. null/'any' are the only
     * cross-context-safe values; every read path treats null as "no rate filter / all rates".
     *
     * @param  array<int|string, string>  $validRateIds  [rate_id => title] for the current context.
     *                                                   MUST be id-keyed (see overrideRates contract).
     */
    public function reconcileRate(array $validRateIds, bool $ratesEnabled): void
    {
        if (! $ratesEnabled) {
            $this->rate = null;

            return;
        }

        // Drop a numeric rate that isn't valid here (foreign collection, entry-restricted, unpublished, deleted).
        if (is_numeric($this->rate) && ! isset($validRateIds[$this->rate])) {
            $this->rate = null;
        }

        // With exactly one valid rate, select it outright instead of leaving the search rate-less.
        if (($this->rate === null || $this->rate === 'any') && count($validRateIds) === 1) {
            $this->rate = (string) array_key_first($validRateIds);
        }
    }

    public function hasDates(): bool
    {
        return isset($this->dates['date_start']) && isset($this->dates['date_end']);
    }
}

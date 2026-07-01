<?php

namespace Reach\StatamicResrv\Http\Requests;

use Carbon\CarbonPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Reach\StatamicResrv\Facades\Availability;
use Reach\StatamicResrv\Rules\ResrvAvailabilityExists;

class AvailabilityCpRequest extends FormRequest
{
    public function rules(): array
    {
        // Bulk path: one atomic request carrying a group ({price, available, rate_ids}) per
        // editability signature. Existence is validated per group in withValidator() because the
        // single-field requirement applies to each group's rate set, not the request as a whole.
        if ($this->has('groups')) {
            return [
                'statamic_id' => ['required'],
                'date_start' => ['required', 'date'],
                'date_end' => ['required', 'date'],
                'groups' => ['required', 'array', 'min:1'],
                'groups.*.price' => ['nullable', 'numeric'],
                'groups.*.available' => ['nullable', 'numeric'],
                'groups.*.rate_ids' => ['required', 'array', 'min:1'],
                'groups.*.rate_ids.*' => ['integer', Rule::exists('resrv_rates', 'id')->whereNull('deleted_at')],
                'onlyDays' => ['sometimes', 'array'],
                'onlyDays.*' => ['integer', 'between:0,6'],
            ];
        }

        return [
            'statamic_id' => ['required'],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date'],
            'price' => ['nullable', 'numeric', 'required_without:available', new ResrvAvailabilityExists],
            'available' => ['nullable', 'numeric', 'required_without:price', new ResrvAvailabilityExists],
            'rate_ids' => ['sometimes', 'array'],
            'rate_ids.*' => ['integer', Rule::exists('resrv_rates', 'id')->whereNull('deleted_at')],
            'onlyDays' => ['sometimes', 'array'],
            'onlyDays.*' => ['integer', 'between:0,6'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Bail if standard rules failed, so the DB-backed checks below don't run on invalid
            // input and 500.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $onlyDays = $this->input('onlyDays');

            // onlyDays that intersects the selected range to nothing would write no day at all. The
            // single-field existence check below also rejects this, but a combined edit (grouped or
            // not) skips that check and would otherwise report a vacuous 200 with zero writes — so
            // reject it here for every path.
            if (! empty($onlyDays) && $this->onlyDaysMissDateRange($onlyDays)) {
                $validator->errors()->add('onlyDays', __('The selected days of the week do not fall within the date range.'));

                return;
            }

            if (! $this->has('groups')) {
                return;
            }

            $groups = (array) $this->input('groups', []);

            // Resolved base-rate ids whose availability rows a combined (both-field) group in THIS
            // request will create/update. A single-field group that depends on those rows (e.g. a
            // shared rate's price override resolving to the base pool) must not be rejected for not
            // finding them at validation time — the controller applies combined groups first.
            $createdBaseRateIds = [];
            foreach ($groups as $group) {
                if (is_null($group['price'] ?? null) || is_null($group['available'] ?? null)) {
                    continue;
                }
                foreach ((array) ($group['rate_ids'] ?? []) as $rateId) {
                    $createdBaseRateIds[] = Availability::resolveBaseRateId((int) $rateId);
                }
            }
            $createdBaseRateIds = array_unique($createdBaseRateIds);

            foreach ($groups as $i => $group) {
                $price = $group['price'] ?? null;
                $available = $group['available'] ?? null;

                if (is_null($price) && is_null($available)) {
                    $validator->errors()->add("groups.{$i}", __('Each group must set a price or availability.'));

                    continue;
                }

                // A combined group (both fields) can create new dates, so it needs no existence check.
                if (! is_null($price) && ! is_null($available)) {
                    continue;
                }

                // A single-field group cannot create a date, so every targeted rate must already have
                // priced rows for the full range — unless a sibling combined group in this request
                // will create them first (same rule the non-grouped path enforces via ResrvAvailabilityExists).
                foreach ((array) ($group['rate_ids'] ?? []) as $rateId) {
                    // Safe to skip the existence check only because the controller applies combined
                    // groups first within the same atomic transaction (AvailabilityCpController::update),
                    // so the resolved base row this single-field group depends on is created before it runs.
                    if (in_array(Availability::resolveBaseRateId((int) $rateId), $createdBaseRateIds, true)) {
                        continue;
                    }

                    if (! Availability::itemsExistAndHavePrices(
                        $this->input('date_start'),
                        $this->input('date_end'),
                        $this->input('statamic_id'),
                        (int) $rateId,
                        $onlyDays
                    )) {
                        $validator->errors()->add("groups.{$i}", __('The availability does not exist or does not have prices for the selected date range.'));

                        break;
                    }
                }
            }
        });
    }

    /**
     * True when none of the days in the (inclusive) date range fall on a selected weekday, so the
     * edit would write nothing. Non-strict comparison + inclusive range mirror the controller's
     * write loop (AvailabilityCpController::updateAvailability).
     *
     * @param  array<int, int|string>  $onlyDays
     */
    protected function onlyDaysMissDateRange(array $onlyDays): bool
    {
        return collect(CarbonPeriod::create($this->input('date_start'), $this->input('date_end')))
            ->every(fn ($day) => ! in_array($day->dayOfWeek, $onlyDays));
    }
}

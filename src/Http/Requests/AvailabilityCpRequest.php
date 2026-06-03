<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicResrv\Rules\ResrvAvailabilityExists;

class AvailabilityCpRequest extends FormRequest
{
    public function rules(): array
    {
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
}

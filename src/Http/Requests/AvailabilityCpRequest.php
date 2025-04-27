<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicResrv\Rules\ResrvAvailabilityExists;

class AvailabilityCpRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'statamic_id' => ['required'],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date'],
            'price' => ['nullable', 'numeric', 'required_if:available,null', new ResrvAvailabilityExists],
            'available' => ['nullable', 'numeric', 'required_if:price,null', new ResrvAvailabilityExists],
            'advanced' => ['sometimes', 'array'],
            'onlyDays' => ['sometimes', 'array'],
            'onlyDays.*' => ['integer', 'between:0,6'],
        ];
    }
}

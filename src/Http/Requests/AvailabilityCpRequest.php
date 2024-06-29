<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'price' => ['required_unless:available_only,true', 'numeric'],
            'available' => ['required', 'numeric'],
            'advanced' => ['sometimes', 'array'],
            'available_only' => ['sometimes', 'boolean'],
        ];
    }
}

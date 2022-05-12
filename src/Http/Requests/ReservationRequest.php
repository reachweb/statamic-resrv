<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReservationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        if ($this->request->has('dates')) {
            $rules = [
                'dates' => 'required|array',
                'dates.*.date_start' => 'required|date',
                'dates.*.date_end' => 'required|date',
                'dates.*.quantity' => 'sometimes|integer',
                'dates.*.advanced' => 'nullable|string',
                'payment' => 'required|numeric',
                'price' => 'required|numeric',
                'total' => 'required|numeric',
                'extras' => 'nullable|array',
                'options' => 'nullable|array',
                'statamic_id' => 'nullable|string',
            ];
        } else {
            $rules = [
                'date_start' => 'required|date',
                'date_end' => 'required|date',
                'quantity' => 'sometimes|integer',
                'advanced' => 'nullable|string',
                'payment' => 'required|numeric',
                'price' => 'required|numeric',
                'total' => 'required|numeric',
                'extras' => 'nullable|array',
                'options' => 'nullable|array',
                'statamic_id' => 'nullable|string',
            ];
        }

        if (config('resrv-config.enable_locations') == true) {
            $additional_rules = [
                'location_start' => 'required|integer',
                'location_end' => 'required|integer',
            ];
            $rules = array_merge($rules, $additional_rules);
        }

        return $rules;
    }
}

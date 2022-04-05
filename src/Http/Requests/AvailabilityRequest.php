<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        if ($this->request->has('dates')) {
            return [
                'dates' => 'required|array',
                'dates.*.date_start' => 'required|date',
                'dates.*.date_end' => 'required|date',
                'dates.*.quantity' => 'sometimes|integer',
            ];
        }

        return [
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'quantity' => 'sometimes|integer',
        ];
    }
}

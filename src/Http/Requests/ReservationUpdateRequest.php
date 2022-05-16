<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReservationUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'payment' => 'required|numeric',
            'price' => 'required|numeric',
            'total' => 'required|numeric',
            'extras' => 'nullable|array',
            'options' => 'nullable|array',
        ];
    }
}
